<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Campaigns\AttemptResult;
use App\Domain\Campaigns\Campaign;
use App\Domain\Campaigns\CampaignLauncher;
use App\Domain\Campaigns\CampaignRecipientStatus;
use App\Domain\Campaigns\CampaignRunner;
use App\Domain\Campaigns\CampaignStatus;
use App\Domain\Campaigns\CampaignSummary;
use App\Domain\Campaigns\DeliveryAttempt;
use App\Domain\Campaigns\RetryPolicy;
use App\Domain\Campaigns\SmtpFailureClassifier;
use App\Domain\Mail\DeliveryOutcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;

/**
 * The submission outcome model, and specifically the case where it is silent.
 *
 * Everything in this file exists because of one fact about SMTP: a provider can
 * accept a message and the connection can fail before PHP reads the `250`. The
 * platform then holds a message that may be in a queue somewhere and no evidence
 * either way.
 *
 * The tempting move is to treat a missing reply as a connection blip and retry. That
 * is what this code used to do, and these tests are the reason it no longer does —
 * `test_an_unconfirmed_submission_is_never_retried` would fail against the previous
 * classification, and failing it is the whole point.
 */
class DeliveryOutcomeModelTest extends CampaignTestCase
{
    #[Test]
    public function an_exception_with_no_status_code_is_ambiguous_rather_than_a_temporary_failure(): void
    {
        $result = $this->classify('Connection reset by peer');

        $this->assertSame(DeliveryOutcome::Ambiguous, $result['outcome']);
        $this->assertNull($result['code']);
    }

    #[Test]
    public function an_explicit_four_xx_is_still_a_temporary_failure(): void
    {
        // The distinction that makes the ambiguous case worth recording: a server
        // that says "come back later" has told us it did not take the message, so
        // sending it again later is correct and not a duplicate.
        $result = $this->classify('451 4.3.0 Temporary error, try again later');

        $this->assertSame(DeliveryOutcome::TemporaryFailure, $result['outcome']);
        $this->assertSame('451', $result['code']);
    }

    #[Test]
    public function a_5xx_is_still_a_permanent_failure(): void
    {
        // Not a 5.7.x: that enhanced code is about policy and authentication and is
        // classified as a credentials problem, which is the other half of the
        // recipient-versus-transport distinction.
        $result = $this->classify('550 5.2.2 Message too large for system');

        $this->assertSame(DeliveryOutcome::PermanentFailure, $result['outcome']);
        $this->assertSame('550', $result['code']);
    }

    #[Test]
    public function a_rejected_login_is_a_transport_problem_and_not_a_message_problem(): void
    {
        // The distinction that stops one refused login from marking a thousand
        // recipients failed: nothing about this recipient caused it.
        $this->assertSame(
            DeliveryOutcome::AuthenticationRejected,
            $this->classify('535 5.7.8 Authentication credentials invalid')['outcome'],
        );

        // And a mailbox that does not exist is the opposite: one address, and the
        // rest of the list is fine.
        $this->assertSame(
            DeliveryOutcome::RecipientRejected,
            $this->classify('550 5.1.1 <nope@example.com>: Recipient address rejected: User unknown')['outcome'],
        );
    }

    #[Test]
    public function a_missing_reply_does_not_retry_because_the_message_may_already_be_accepted(): void
    {
        // The invariant, stated as an assertion rather than as a comment: the retry
        // policy has no answer at all for an ambiguous outcome.
        $policy = app(RetryPolicy::class);

        $this->assertFalse(AttemptResult::Ambiguous->isRetryable());
        $this->assertNull($policy->delayFor(AttemptResult::Ambiguous, 1));
        $this->assertNull($policy->delayFor(AttemptResult::Ambiguous, 99));
    }

    #[Test]
    public function an_unconfirmed_submission_becomes_its_own_recipient_state(): void
    {
        $campaign = $this->campaignThatCannotSayWhatHappened(1);

        $recipient = $campaign->recipients()->firstOrFail();

        $this->assertSame(CampaignRecipientStatus::Unknown, $recipient->status);
        $this->assertNull($recipient->next_attempt_at, 'A message of unknown outcome must not be queued for another attempt.');
        $this->assertTrue($recipient->status->isTerminal());
    }

    #[Test]
    public function an_unconfirmed_submission_is_not_counted_as_sent_or_as_refused(): void
    {
        $campaign = $this->campaignThatCannotSayWhatHappened(1);

        $counts = $campaign->recipientCounts();

        $this->assertSame(1, $counts[CampaignRecipientStatus::Unknown->value]);
        $this->assertSame(0, $counts[CampaignRecipientStatus::Sent->value]);
        $this->assertSame(0, $counts[CampaignRecipientStatus::Failed->value]);

        // It *is* settled: nothing is outstanding, so a campaign made entirely of
        // these finishes rather than spinning forever waiting for work that will
        // never be retried.
        $progress = CampaignSummary::of($campaign, $counts)->progress;

        $this->assertSame(1, $progress->settled());
        $this->assertSame(0, $progress->remaining());
        $this->assertSame(0, $progress->count(CampaignRecipientStatus::Sent));
    }

    #[Test]
    public function an_unconfirmed_submission_is_never_retried(): void
    {
        $campaign = $this->campaignThatCannotSayWhatHappened(4);

        $first = $campaign->recipients()->orderBy('id')->firstOrFail();

        $this->assertSame(CampaignRecipientStatus::Unknown, $first->status);
        $this->assertSame(1, (int) $first->attempts);

        // Give the worker every opportunity to pick it up again: make every
        // recipient due and run again.
        for ($pass = 0; $pass < 3; $pass++) {
            DB::table('campaign_recipients')->where('campaign_id', $campaign->id)
                ->update(['next_attempt_at' => now()->subDay()]);

            DB::table('campaigns')->where('id', $campaign->id)
                ->update(['next_send_at' => now()->subDay()]);

            app(CampaignRunner::class)->run($campaign->fresh());
        }

        $this->assertSame(
            1,
            (int) $first->fresh()->attempts,
            'An ambiguous submission was attempted more than once, which is the duplicate mail this state exists to prevent.',
        );

        // One attempt row, for one attempt. The history is append-only and does not
        // grow because a worker ran again.
        $this->assertSame(1, DeliveryAttempt::query()
            ->where('campaign_recipient_id', $first->id)
            ->count());
    }

    #[Test]
    public function a_streak_of_unconfirmed_submissions_stops_the_campaign(): void
    {
        // Without this, a dead host would walk the whole audience leaving every
        // recipient unconfirmed: a campaign that reached nobody and called itself
        // finished, with an unaccounted message in a queue for each.
        //
        // Driven as three separate worker invocations, because that is what pacing
        // produces: a run sends one message, then defers until the interval has
        // passed. A streak held in memory would reset between them and never fire.
        $campaign = $this->campaignThatCannotSayWhatHappened(12, runOnce: false);

        $this->assertSame(CampaignStatus::Running, $campaign->fresh()->status);

        for ($invocation = 1; $invocation <= 3; $invocation++) {
            $this->transport->answering(DeliveryOutcome::Ambiguous, null, 'Connection reset by peer');

            DB::table('campaigns')->where('id', $campaign->id)
                ->update(['next_send_at' => now()->subSecond()]);

            app(CampaignRunner::class)->run($campaign->fresh());

            if ($invocation < 3) {
                $this->assertSame(
                    CampaignStatus::Running,
                    $campaign->fresh()->status,
                    'The campaign stopped after '.$invocation.' unconfirmed submissions. One or two may be a '
                        .'genuine blip; the limit exists because three in a row is a transport, not bad luck.',
                );
            }
        }

        $this->assertSame(CampaignStatus::Failed, $campaign->fresh()->status);

        $unknown = $campaign->recipients()
            ->where('status', CampaignRecipientStatus::Unknown->value)
            ->count();

        $this->assertSame(3, $unknown, 'The streak limit bounds how many messages can be left unaccounted for.');

        $byState = $campaign->recipients()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        ksort($byState);

        $this->assertSame(
            ['queued' => 9, 'unknown' => 3],
            $byState,
            'Only the three unconfirmed submissions should have been attempted; nothing was accepted and nothing'
                .' else was touched.',
        );
    }

    #[Test]
    public function the_campaign_says_why_it_stopped_rather_than_only_that_it_did(): void
    {
        $campaign = $this->campaignThatCannotSayWhatHappened(12, runOnce: false);

        for ($invocation = 1; $invocation <= 3; $invocation++) {
            $this->transport->answering(DeliveryOutcome::Ambiguous, null, 'Connection reset by peer');

            DB::table('campaigns')->where('id', $campaign->id)
                ->update(['next_send_at' => now()->subSecond()]);

            app(CampaignRunner::class)->run($campaign->fresh());
        }

        $reason = (string) $campaign->fresh()->failure_reason;

        $this->assertStringContainsString('stopped replying', $reason);
        $this->assertStringContainsString('may have been accepted', $reason);
        $this->assertStringContainsString('twice', $reason);
    }

    #[Test]
    public function the_stored_reason_says_why_the_message_was_not_sent_again(): void
    {
        $campaign = $this->campaignThatCannotSayWhatHappened(1);

        $reason = (string) $campaign->recipients()->firstOrFail()->last_error_message;

        $this->assertStringContainsString('Connection reset by peer', $reason);
    }

    #[Test]
    public function the_campaign_page_tells_the_truth_about_an_unconfirmed_message(): void
    {
        $campaign = $this->campaignThatCannotSayWhatHappened(1);

        $body = $this->actingAs($campaign->user)
            ->get(route('campaigns.show', $campaign))
            ->assertOk()
            ->getContent();

        $this->assertIsString($body);

        // Not "sent", not "failed", and not a claim of delivery.
        $this->assertStringContainsString('No reply', $body);
        $this->assertStringNotContainsString('delivered', strtolower($body));
    }

    #[Test]
    public function the_attempt_history_records_the_outcome_it_actually_had(): void
    {
        $campaign = $this->campaignThatCannotSayWhatHappened(1);

        $attempt = DeliveryAttempt::query()->firstOrFail();

        $this->assertSame(AttemptResult::Ambiguous, $attempt->result);
        $this->assertSame('ambiguous', $attempt->result->value);
    }

    /**
     * The transport's classification of an exception's text.
     *
     * Called directly rather than through a submission, because the decision under test
     * is precisely the one made when there is no reply to read, and there is no reply
     * to read in a test. Which is also why the classifier is its own class: it used to
     * live in a private method on the transport, reachable only by reflection.
     *
     * @return array{outcome: DeliveryOutcome, code: string|null}
     */
    private function classify(string $exceptionText): array
    {
        return app(SmtpFailureClassifier::class)->classify($exceptionText);
    }

    /**
     * A running campaign whose transport accepts the message and then falls silent.
     *
     * `$runOnce` false leaves the campaign as it was launched, so a test can drive
     * the worker itself — which is what a streak of three requires, since pacing
     * means each of those submissions happens in its own invocation.
     */
    private function campaignThatCannotSayWhatHappened(int $recipients, bool $runOnce = true): Campaign
    {
        Queue::fake();

        $user = $this->signedInTenant();
        $list = $this->listFor($user);

        for ($i = 0; $i < $recipients; $i++) {
            $this->eligibleContact($user, $list);
        }

        $campaign = $this->draftFor($user, ['list_id' => $list->id]);

        app(CampaignLauncher::class)->launchNow($campaign);

        $campaign = $campaign->fresh();

        if (! $runOnce) {
            return $campaign;
        }

        // No status code at all: the exchange ended without the server saying
        // anything, which is the case this whole stage exists to get right.
        $this->transport->answering(DeliveryOutcome::Ambiguous, null, 'Connection reset by peer');

        DB::table('campaigns')->where('id', $campaign->id)
            ->update(['next_send_at' => now()->subSecond()]);

        app(CampaignRunner::class)->run($campaign->fresh());

        return $campaign->fresh();
    }
}

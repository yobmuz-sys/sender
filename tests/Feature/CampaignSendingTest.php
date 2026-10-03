<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Audience\SuppressionList;
use App\Domain\Audience\SuppressionReason;
use App\Domain\Campaigns\Campaign;
use App\Domain\Campaigns\CampaignLauncher;
use App\Domain\Campaigns\CampaignRecipientStatus;
use App\Domain\Campaigns\CampaignRunner;
use App\Domain\Campaigns\CampaignRunOutcome;
use App\Domain\Campaigns\CampaignStatus;
use App\Domain\Campaigns\DeliveryAttempt;
use App\Domain\Mail\DeliveryOutcome;
use App\Jobs\ProcessCampaignJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Sending: the durable state machine, the pacing, and what survives a restart.
 *
 * Every test drives {@see CampaignRunner} directly rather than going through a
 * job, because the runner *is* the send and the job only calls it. What is
 * asserted is what a campaign looks like after the PHP process is gone: which
 * recipients are due, which have been attempted, what the server said, and whether
 * a second worker could double-send.
 *
 * Two behaviours are worth stating up front, because the tests look surprising
 * without them:
 *
 *   - **A fresh run sends one message.** The minimum interval is enforced against
 *     a durable clock, not a sleep, so a worker that finishes a message finds the
 *     next one not yet due and hands the run back to the queue. Throughput is
 *     therefore governed by the host's scheduler — which is exactly what the
 *     campaign page says out loud.
 *   - **A run that is behind catches up at the configured average rate.** If the
 *     worker was down for ten minutes, the clock is ten minutes behind and the run
 *     may send until the clock catches up, bounded by the batch size. The rate is
 *     an average, never exceeded, and a restart does not waste the backlog.
 *
 * No test opens a socket. The transport is a recording double, which is what makes
 * it possible to assert on a refused login, a throttle and an accepted message in
 * the same place a real provider would be indistinguishable from them.
 */
class CampaignSendingTest extends CampaignTestCase
{
    public function test_a_queued_recipient_is_sent_and_recorded_as_sent(): void
    {
        $campaign = $this->runningCampaign(1);

        $outcome = $this->runCampaign($campaign);

        $this->assertSame(1, $outcome->sent);
        $this->assertSame(1, $this->transport->submissions());

        $recipient = $campaign->recipients()->sole();

        $this->assertSame('sent', $recipient->status->value);
        $this->assertSame(1, $recipient->attempts);
        $this->assertNotNull($recipient->sent_at);

        $counts = $campaign->recipientCounts();

        $this->assertSame(1, $counts[CampaignRecipientStatus::Sent->value]);
        $this->assertSame(1, $counts['total']);
    }

    public function test_the_message_sent_is_the_frozen_snapshot_with_the_recipients_own_values(): void
    {
        $campaign = $this->runningCampaign(1);

        $this->runCampaign($campaign);

        $submitted = $this->transport->submitted[0];

        $this->assertSame($campaign->subject_snapshot, $submitted['subject']);
        $this->assertStringNotContainsString('{{unsubscribe_url}}', (string) $submitted['html']);
        $this->assertStringNotContainsString('{{first_name}}', (string) $submitted['html']);
        $this->assertStringStartsWith(url('/unsubscribe/'), $submitted['unsubscribe']);
        $this->assertSame('alice@example.com', $submitted['from']);
    }

    public function test_every_recipient_gets_their_own_unsubscribe_link(): void
    {
        $campaign = $this->runningCampaign(3);

        $this->sendUntilEmpty($campaign);

        $links = array_column($this->transport->submitted, 'unsubscribe');

        $this->assertCount(3, $links);
        $this->assertCount(3, array_unique($links), 'A shared link would let one recipient unsubscribe another.');
    }

    public function test_recipient_values_are_filled_in_and_nothing_is_left_unresolved(): void
    {
        $campaign = $this->runningCampaign(1);

        $this->runCampaign($campaign);

        $html = (string) $this->transport->submitted[0]['html'];
        $text = (string) $this->transport->submitted[0]['text'];

        $this->assertStringNotContainsString('{{', $html);
        $this->assertStringNotContainsString('{{', $text);
        $this->assertStringContainsString('Hello ', $html, 'A known field with no value becomes empty, not literal.');
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_attempt_history_is_written_for_every_send(): void
    {
        $campaign = $this->runningCampaign(1);

        $this->runCampaign($campaign);

        $attempt = DeliveryAttempt::query()->sole();

        $this->assertSame('accepted', $attempt->result->value);
        $this->assertSame(1, $attempt->attempt_number);
        $this->assertNotNull($attempt->finished_at);
        $this->assertNotNull($attempt->provider_message_id);
        $this->assertSame($campaign->recipients()->sole()->provider_message_id, $attempt->provider_message_id);
    }

    public function test_a_run_sends_one_message_and_then_hands_the_next_opportunity_back(): void
    {
        $campaign = $this->runningCampaign(3, ['rate_interval_seconds' => 30]);

        $outcome = $this->runCampaign($campaign);

        // One message, because the next one is not due for another 30 seconds and
        // a worker does not sit there waiting for it.
        $this->assertSame(1, $this->transport->submissions());
        $this->assertSame('deferred', $outcome->action);
        $this->assertGreaterThan(0, $outcome->deferredSeconds);

        $next = $campaign->fresh()->next_send_at;

        $this->assertNotNull($next);
        $this->assertTrue(
            $next->between(now()->addSeconds(25), now()->addSeconds(35)),
            'The next send is one interval after the last, not after whenever the worker woke up.',
        );
    }

    public function test_a_run_that_is_behind_catches_up_at_the_configured_rate_up_to_the_batch_size(): void
    {
        $campaign = $this->runningCampaign(20, [
            'rate_interval_seconds' => 30,
            'worker_batch_size' => 5,
        ]);

        // The worker was down for an hour. The clock is an hour behind, so the run
        // may send until it catches up — never faster than 30 seconds apart on
        // average, and never more than the batch in one pass.
        $this->rewindPaceBy($campaign, 60);

        $outcome = $this->runCampaign($campaign);

        $this->assertSame(5, $outcome->sent);
        $this->assertSame(5, $this->transport->submissions());
        $this->assertSame(15, $campaign->remainingCount());
        $this->assertSame(CampaignStatus::Running, $campaign->fresh()->status);
    }

    public function test_a_temporary_failure_schedules_a_bounded_retry_and_keeps_the_attempt(): void
    {
        $campaign = $this->runningCampaign(1);

        $this->transport->answering(DeliveryOutcome::TemporaryFailure, '451', 'Please try later.');

        $this->runCampaign($campaign);

        $recipient = $campaign->recipients()->sole();

        $this->assertSame('queued', $recipient->status->value);
        $this->assertSame(1, $recipient->attempts);
        $this->assertNotNull($recipient->next_attempt_at);
        $this->assertSame('451', $recipient->last_error_code);
        $this->assertSame('temporary_failure', DeliveryAttempt::query()->sole()->result->value);

        $this->assertSame(1, $campaign->fresh()->remainingCount());
        $this->assertSame(CampaignStatus::Running, $campaign->fresh()->status);
    }

    public function test_a_retry_is_not_attempted_before_its_time(): void
    {
        $campaign = $this->runningCampaign(1);

        $this->transport->answering(DeliveryOutcome::TemporaryFailure, '451', 'Come back later.');

        $this->runCampaign($campaign);

        $this->assertSame(1, $this->transport->submissions());

        $outcome = $this->runCampaign($campaign);

        $this->assertSame(0, $outcome->sent);
        $this->assertSame(1, $this->transport->submissions(), 'A backoff that was ignored would be no backoff.');
    }

    public function test_a_recipient_is_given_up_on_after_the_configured_number_of_attempts(): void
    {
        config(['sender.campaigns.max_attempts' => 2]);

        $campaign = $this->runningCampaign(1);

        $this->transport
            ->answering(DeliveryOutcome::Accepted)
            ->answerNext(DeliveryOutcome::TemporaryFailure, '451', 'Still busy.')
            ->answerNext(DeliveryOutcome::TemporaryFailure, '451', 'Still busy.');

        $this->runCampaign($campaign);

        $this->assertSame('queued', $campaign->recipients()->sole()->status->value);

        // Move past the backoff so the second attempt is actually due.
        $this->makeRetriesDue($campaign);
        $this->makeOneSendDue($campaign);

        $this->runCampaign($campaign);

        $recipient = $campaign->recipients()->sole();

        $this->assertSame('failed', $recipient->status->value);
        $this->assertSame(2, $recipient->attempts);
        $this->assertNull($recipient->next_attempt_at, 'A failed recipient must not be scheduled again.');
        $this->assertSame(2, DeliveryAttempt::query()->count());
    }

    public function test_a_refused_recipient_is_failed_immediately_and_never_retried(): void
    {
        $campaign = $this->runningCampaign(1);

        $this->transport->answering(DeliveryOutcome::RecipientRejected, '550', '5.1.1 No such user.');

        $this->runCampaign($campaign);

        $recipient = $campaign->recipients()->sole();

        $this->assertSame('failed', $recipient->status->value);
        $this->assertSame(1, $recipient->attempts);
        $this->assertNull($recipient->next_attempt_at);
        $this->assertSame('550', $recipient->last_error_code);
        $this->assertSame('5.1.1 No such user.', $recipient->last_error_message);

        $this->runCampaign($campaign);

        $this->assertSame(1, $this->transport->submissions(), 'A refused address must not be tried again.');
    }

    public function test_a_transport_failure_stops_the_campaign_rather_than_switching_transports(): void
    {
        $campaign = $this->runningCampaign(3);

        $this->transport->answering(DeliveryOutcome::AuthenticationRejected, '535', 'Authentication failed.');

        $outcome = $this->runCampaign($campaign);

        $stopped = $campaign->fresh();

        $this->assertSame('stopped', $outcome->action);
        $this->assertSame('failed', $stopped->status->value);
        $this->assertNotNull($stopped->failure_reason);
        $this->assertStringContainsString('rather than switched to another transport', (string) $stopped->failure_reason);
        $this->assertSame(1, $this->transport->submissions(), 'The rest of the list must not be pressed at a broken transport.');
        $this->assertSame(2, $stopped->remainingCount());
    }

    public function test_a_recipient_who_unsubscribes_after_the_snapshot_is_blocked_before_sending(): void
    {
        $campaign = $this->runningCampaign(3);

        $first = $campaign->recipients()->orderBy('id')->first();
        app(SuppressionList::class)->suppress($first->contact, SuppressionReason::Unsubscribed);

        $this->sendUntilEmpty($campaign);

        $first->refresh();

        $this->assertSame('blocked', $first->status->value);
        $this->assertSame(0, $first->attempts, 'Nothing was attempted, so no attempt is counted.');
        $this->assertSame(2, $this->transport->submissions());
        $this->assertSame('blocked', DeliveryAttempt::query()
            ->where('campaign_recipient_id', $first->id)
            ->sole()
            ->result->value);

        // And the campaign still finished, with the blocked recipient counted.
        $this->assertSame('completed', $campaign->fresh()->status->value);
        $this->assertSame(1, $campaign->fresh()->recipientCounts()[CampaignRecipientStatus::Blocked->value]);
    }

    public function test_a_contact_deleted_after_the_snapshot_is_skipped_and_the_record_survives(): void
    {
        $campaign = $this->runningCampaign(3);

        $victim = $campaign->recipients()->orderBy('id')->first();
        $email = $victim->email;

        $victim->contact->delete();

        $this->sendUntilEmpty($campaign);

        $survivor = $campaign->recipients()->where('id', $victim->id)->sole();

        $this->assertSame('skipped', $survivor->status->value);
        $this->assertNull($survivor->contact_id);
        $this->assertSame($email, $survivor->email, 'A campaign keeps its own record of who it was going to contact.');
        $this->assertSame(2, $this->transport->submissions());
        $this->assertSame(3, $campaign->fresh()->recipientCounts()['total'], 'Deleting a contact must not rewrite the totals.');
    }

    public function test_a_completed_campaign_is_detected_rather_than_polled_for_forever(): void
    {
        $campaign = $this->runningCampaign(1);

        $outcome = $this->sendUntilEmpty($campaign);

        $this->assertSame('completed', $outcome->action);
        $this->assertSame('completed', $campaign->fresh()->status->value);
        $this->assertNotNull($campaign->fresh()->completed_at);
        $this->assertSame(0, $campaign->remainingCount());
    }

    public function test_a_second_run_continues_where_the_first_stopped(): void
    {
        $campaign = $this->runningCampaign(5);

        $this->runCampaign($campaign);
        $this->assertSame(1, $this->transport->submissions());

        $this->makeOneSendDue($campaign);

        $this->runCampaign($campaign);

        $this->assertSame(2, $this->transport->submissions());
        $this->assertSame(2, $campaign->fresh()->recipientCounts()[CampaignRecipientStatus::Sent->value]);
    }

    public function test_a_paused_campaign_sends_nothing(): void
    {
        $campaign = $this->runningCampaign(3);
        $campaign->pause();

        $outcome = $this->runCampaign($campaign);

        $this->assertSame('skipped', $outcome->action);
        $this->assertSame(0, $this->transport->submissions());
        $this->assertSame('paused', $campaign->fresh()->status->value);
    }

    public function test_resuming_continues_the_queued_work(): void
    {
        $campaign = $this->runningCampaign(4);

        $this->runCampaign($campaign);
        $campaign->pause();
        $this->runCampaign($campaign);

        $this->assertSame(1, $this->transport->submissions());

        $campaign->resume();

        $this->runCampaign($campaign);

        $this->assertSame(2, $this->transport->submissions());
        $this->assertSame('running', $campaign->fresh()->status->value);
    }

    public function test_cancelling_stops_the_work_and_settles_the_remaining_recipients(): void
    {
        $campaign = $this->runningCampaign(5);

        $this->runCampaign($campaign);
        $campaign->cancel();

        $outcome = $this->runCampaign($campaign);

        $this->assertSame('skipped', $outcome->action);
        $this->assertSame(1, $this->transport->submissions());

        $counts = $campaign->recipientCounts();

        $this->assertSame(1, $counts[CampaignRecipientStatus::Sent->value]);
        $this->assertSame(4, $counts[CampaignRecipientStatus::Skipped->value]);
        $this->assertSame(0, $campaign->remainingCount());
    }

    public function test_a_cancelled_campaign_is_never_resurrected_by_a_job_already_in_the_queue(): void
    {
        $campaign = $this->runningCampaign(3);
        $campaign->cancel();

        app(CampaignRunner::class)->run($campaign->fresh());

        $this->assertSame(0, $this->transport->submissions());
        $this->assertSame('cancelled', $campaign->fresh()->status->value);
    }

    public function test_a_second_worker_cannot_claim_a_campaign_that_is_already_claimed(): void
    {
        $campaign = $this->runningCampaign(2);

        $this->assertTrue($campaign->claimForWorker(900));

        $other = Campaign::query()->find($campaign->id);

        $this->assertFalse($other->claimForWorker(900));
        $this->assertSame('skipped', app(CampaignRunner::class)->run($other)->action);
        $this->assertSame(0, $this->transport->submissions());
    }

    public function test_a_claim_left_by_a_killed_worker_is_recoverable(): void
    {
        $campaign = $this->runningCampaign(1);

        $campaign->claimForWorker(900);

        // Older than the staleness window: the worker that held it is gone, which
        // on shared hosting is an ordinary Tuesday rather than an exception.
        DB::table('campaigns')->where('id', $campaign->id)
            ->update(['worker_claimed_at' => now()->subHour()]);

        $outcome = $this->runCampaign($campaign);

        $this->assertSame(1, $outcome->sent);
    }

    public function test_two_workers_cannot_send_the_same_recipient(): void
    {
        $campaign = $this->runningCampaign(1);

        $recipient = $campaign->recipients()->sole();
        $competitor = $campaign->recipients()->sole();

        $this->assertTrue($recipient->claim(900), 'The first worker takes the claim.');
        $this->assertFalse($competitor->claim(900), 'The second is refused by the database, not by a read.');
        $this->assertSame(0, $this->transport->submissions());
    }

    public function test_a_recipient_stuck_sending_by_a_dead_worker_is_taken_back(): void
    {
        $campaign = $this->runningCampaign(1);

        $recipient = $campaign->recipients()->sole();

        DB::table('campaign_recipients')->where('id', $recipient->id)->update([
            'status' => CampaignRecipientStatus::Sending->value,
            'claimed_at' => now()->subHour(),
        ]);

        $this->runCampaign($campaign);

        $this->assertSame(1, $this->transport->submissions());
        $this->assertSame('sent', $recipient->fresh()->status->value);
    }

    public function test_a_scheduled_campaign_waits_for_its_time(): void
    {
        $campaign = $this->scheduledCampaign(1, now()->addHour());

        $outcome = $this->runCampaign($campaign);

        $this->assertSame(0, $this->transport->submissions());
        $this->assertSame('scheduled', $campaign->fresh()->status->value);
        $this->assertGreaterThan(0, $outcome->deferredSeconds);
    }

    public function test_a_scheduled_campaign_that_no_longer_passes_its_checks_does_not_start(): void
    {
        // Its start time has arrived, but the transport's verification has expired
        // since the campaign was prepared.
        $campaign = $this->scheduledCampaign(1, now()->subMinute());

        $campaign->smtpAccount->markVerified(-1);

        $outcome = $this->runCampaign($campaign);

        $this->assertSame('stopped', $outcome->action);
        $this->assertSame(0, $this->transport->submissions());
        $this->assertSame('failed', $campaign->fresh()->status->value);
    }

    public function test_a_campaign_with_no_transport_left_stops_instead_of_substituting_one(): void
    {
        $campaign = $this->runningCampaign(1);

        DB::table('smtp_accounts')->where('id', $campaign->smtp_account_id)->delete();

        $outcome = $this->runCampaign($campaign);

        $this->assertSame('stopped', $outcome->action);
        $this->assertSame(0, $this->transport->submissions(), 'No other account may be quietly used.');
        $this->assertSame('failed', $campaign->fresh()->status->value);
    }

    public function test_the_worker_hands_the_next_opportunity_back_to_the_queue(): void
    {
        Queue::fake();

        $campaign = $this->runningCampaign(5);

        app(CampaignRunner::class)->run($campaign);

        Queue::assertPushed(ProcessCampaignJob::class);
    }

    public function test_a_draft_is_never_sent_by_a_worker(): void
    {
        $campaign = $this->draftFor($this->signedInTenant());

        $outcome = $this->runCampaign($campaign);

        $this->assertSame('skipped', $outcome->action);
        $this->assertSame(0, $this->transport->submissions());
        $this->assertSame('draft', $campaign->fresh()->status->value);
    }

    public function test_the_campaign_reports_counts_that_add_up(): void
    {
        $campaign = $this->runningCampaign(3);

        $this->sendUntilEmpty($campaign);

        $counts = $campaign->fresh()->recipientCounts();

        $this->assertSame(3, $counts['total']);
        $this->assertSame(
            $counts['total'],
            $counts[CampaignRecipientStatus::Sent->value]
                + $counts[CampaignRecipientStatus::Failed->value]
                + $counts[CampaignRecipientStatus::Queued->value]
                + $counts[CampaignRecipientStatus::Sending->value]
                + $counts[CampaignRecipientStatus::Skipped->value]
                + $counts[CampaignRecipientStatus::Blocked->value],
        );
    }

    /**
     * A launched campaign with this many eligible recipients.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function runningCampaign(int $recipients, array $overrides = []): Campaign
    {
        $campaign = $this->preparedCampaign($recipients, $overrides);

        app(CampaignLauncher::class)->launchNow($campaign);

        return $campaign->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function scheduledCampaign(int $recipients, \DateTimeInterface $startsAt, array $overrides = []): Campaign
    {
        $campaign = $this->preparedCampaign($recipients, array_merge(['scheduled_at' => $startsAt], $overrides));

        app(CampaignLauncher::class)->launch($campaign);

        return $campaign->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function preparedCampaign(int $recipients, array $overrides = []): Campaign
    {
        $user = User::query()->latest('id')->first() ?? $this->signedInTenant();

        $list = $this->listFor($user);

        for ($i = 0; $i < $recipients; $i++) {
            $this->eligibleContact($user, $list);
        }

        return $this->draftFor($user, array_merge(['list_id' => $list->id], $overrides));
    }

    /**
     * Run passes until nothing is left, rewinding the pace clock between them.
     *
     * This is what a host scheduler does — it wakes up later — and it is the only
     * way a campaign makes progress without the process sleeping.
     */
    private function sendUntilEmpty(Campaign $campaign, int $limit = 25): CampaignRunOutcome
    {
        $outcome = CampaignRunOutcome::skipped('never ran');

        for ($i = 0; $i < $limit; $i++) {
            $this->makeOneSendDue($campaign);
            $this->makeRetriesDue($campaign);

            $outcome = $this->runCampaign($campaign);

            if ($outcome->action === 'completed' || $outcome->action === 'stopped') {
                break;
            }
        }

        return $outcome;
    }

    private function runCampaign(Campaign $campaign): CampaignRunOutcome
    {
        Queue::fake();

        // Always from the current stored state. A worker reads the database, not
        // whatever a caller happened to be holding, and a test that passed a stale
        // model would be testing the object rather than the engine.
        return app(CampaignRunner::class)->run($campaign->fresh());
    }

    /**
     * Make exactly one more send due.
     *
     * The pace clock is put one second in the past, so the run is allowed one
     * message and then finds the next one an interval away again — which is what a
     * worker waking a moment after the last message sees. Rewinding further would
     * be a catch-up run, which is a different behaviour with its own test.
     */
    private function makeOneSendDue(Campaign $campaign): void
    {
        DB::table('campaigns')->where('id', $campaign->id)
            ->update(['next_send_at' => now()->subSecond()]);
    }

    private function rewindPaceBy(Campaign $campaign, int $minutes): void
    {
        DB::table('campaigns')->where('id', $campaign->id)
            ->update(['next_send_at' => now()->subMinutes($minutes)]);
    }

    private function makeRetriesDue(Campaign $campaign): void
    {
        DB::table('campaign_recipients')
            ->where('campaign_id', $campaign->id)
            ->update(['next_attempt_at' => now()->subMinute()]);
    }
}

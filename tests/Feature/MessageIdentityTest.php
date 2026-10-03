<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Audience\SuppressionList;
use App\Domain\Audience\SuppressionReason;
use App\Domain\Campaigns\AttemptResult;
use App\Domain\Campaigns\Campaign;
use App\Domain\Campaigns\CampaignLauncher;
use App\Domain\Campaigns\CampaignRecipient;
use App\Domain\Campaigns\CampaignRecipientStatus;
use App\Domain\Campaigns\CampaignRunner;
use App\Domain\Campaigns\CampaignRunOutcome;
use App\Domain\Campaigns\DeliveryAttempt;
use App\Domain\Campaigns\LogicalMessageId;
use App\Domain\Campaigns\MessageTransport;
use App\Domain\Campaigns\RetryPolicy;
use App\Domain\Campaigns\SmtpFailureClassifier;
use App\Domain\Campaigns\SmtpMessageTransport;
use App\Domain\Mail\CampaignMessage;
use App\Domain\Mail\DeliveryOutcome;
use App\Domain\Mail\SmtpAuthMode;
use App\Domain\Mail\SmtpEncryption;
use App\Domain\Mail\SmtpTransportDefinition;
use App\Models\ContactList;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mime\Address;
use Tests\Feature\Fakes\CapturingMailerFactory;
use Tests\Feature\Fakes\RecordingTransport;

/**
 * One identifier per logical message, however many times it is attempted.
 *
 * The `Message-ID` used to be generated inside the SMTP transport, once per call —
 * and a call is an attempt. A message that was throttled and retried therefore went
 * out twice under two unrelated identifiers, which is precisely the case Stage 5D's
 * bounce and complaint correlation has to get right: the provider will complain
 * about a message, and the identifier it quotes has to still mean something.
 *
 * So the tests below are mostly about *one value surviving things*. A retry, a
 * temporary failure, a worker that ends and is replaced, a campaign that is paused
 * and resumed: none of them may produce a second identifier, and none of them may
 * lose the first.
 *
 * The last group is not about the identifier at all, and earns its place for a
 * different reason. {@see SmtpMessageTransport} could not be loaded by a single
 * test in this application until the ambiguous-outcome audit, and it held a PHP
 * fatal error at compile time the entire time — the campaign engine substitutes a
 * recording transport, so the production path was never executed. {@see
 * CapturingMailerFactory} replaces the socket and nothing else, so the real closure
 * runs against a real Symfony message.
 */
class MessageIdentityTest extends CampaignTestCase
{
    public function test_a_recipient_is_given_an_identifier_when_the_campaign_is_launched(): void
    {
        $campaign = $this->runningCampaign(1);

        $recipient = $campaign->recipients()->sole();

        $this->assertNotNull($recipient->message_id);
        $this->assertMatchesRegularExpression(
            '/^campaign-[0-9a-f]{24}@.+$/',
            (string) $recipient->message_id,
            'The identifier has to be one this platform can recognise and one a server will accept.',
        );
    }

    public function test_the_identifier_is_persisted_rather_than_only_held_in_memory(): void
    {
        $campaign = $this->runningCampaign(1);

        $inMemory = $campaign->recipients()->sole()->message_id;

        $stored = DB::table('campaign_recipients')
            ->where('campaign_id', $campaign->id)
            ->value('message_id');

        $this->assertSame($inMemory, $stored, 'A worker restart reads this value from the database, not from a process that is gone.');
    }

    public function test_the_first_submission_uses_the_stored_identifier(): void
    {
        $campaign = $this->runningCampaign(1);

        $expected = $campaign->recipients()->sole()->message_id;

        $this->runCampaign($campaign);

        $this->assertSame($expected, $this->transport->submitted[0]['message_id']);
    }

    public function test_every_attempt_of_one_message_carries_the_same_identifier(): void
    {
        $campaign = $this->runningCampaign(1);

        $expected = $campaign->recipients()->sole()->message_id;

        // Two temporary failures, then an acceptance. Both failures put a message on
        // the wire as far as the server was concerned, so both of them have an
        // identifier a provider may later complain about.
        $this->transport->answerNext(DeliveryOutcome::TemporaryFailure, '451', 'Try later');
        $this->transport->answerNext(DeliveryOutcome::TemporaryFailure, '451', 'Try later');

        $this->sendUntilEmpty($campaign);

        $this->assertSame(3, $this->transport->submissions());

        $this->assertSame(
            [$expected, $expected, $expected],
            array_column($this->transport->submitted, 'message_id'),
            'A retry that changes the identifier turns one message into three unrelated ones to anything that correlates by it.',
        );
    }

    public function test_a_temporary_failure_does_not_replace_the_identifier_it_already_sent_under(): void
    {
        $campaign = $this->runningCampaign(1);

        $before = (string) $campaign->recipients()->sole()->message_id;

        $this->transport->answerNext(DeliveryOutcome::TemporaryFailure, '421', 'Service not available');

        $this->sendUntilEmpty($campaign);

        $recipient = $campaign->recipients()->sole()->fresh();

        // Two attempts: one refused on the way in, one accepted. Both went out under
        // the identifier chosen before the first of them.
        $this->assertSame(2, $recipient->attempts);
        $this->assertSame($before, $recipient->message_id);
        $this->assertSame([$before, $before], array_column($this->transport->submitted, 'message_id'));
    }

    public function test_the_identifier_survives_the_worker_process_being_replaced(): void
    {
        $campaign = $this->runningCampaign(1);

        $expected = $campaign->recipients()->sole()->message_id;

        $this->transport->answerNext(DeliveryOutcome::TemporaryFailure, '451', 'Try later');
        $this->runCampaign($campaign);

        // Everything the next worker knows comes from the database. The PHP objects,
        // the transport double and the container are all new here.
        $this->transport = new RecordingTransport;
        $this->app->instance(MessageTransport::class, $this->transport);

        $this->makeOneSendDue($campaign);
        $this->makeRetriesDue($campaign);

        $this->runCampaign($campaign);

        $this->assertSame($expected, $this->transport->submitted[0]['message_id']);
        $this->assertSame($expected, $campaign->recipients()->sole()->fresh()->message_id);
    }

    public function test_pausing_and_resuming_a_campaign_changes_nothing_about_the_identifier(): void
    {
        $campaign = $this->runningCampaign(2);

        $expected = $campaign->recipients()->get()
            ->map(static fn (CampaignRecipient $recipient): ?string => $recipient->message_id)
            ->all();

        $campaign->pause();
        $campaign->fresh()->resume();

        $this->makeOneSendDue($campaign);
        $this->runCampaign($campaign);

        $after = $campaign->recipients()->get()
            ->map(static fn (CampaignRecipient $recipient): ?string => $recipient->fresh()->message_id)
            ->all();

        sort($expected);
        sort($after);

        $this->assertSame($expected, $after);
        $this->assertNotContains(null, $expected);
    }

    public function test_recipients_of_one_campaign_never_share_an_identifier(): void
    {
        $campaign = $this->runningCampaign(4);

        $ids = $campaign->recipients()->pluck('message_id')->all();

        $this->assertCount(4, array_unique($ids), 'Two logical messages under one identifier would make every future bounce ambiguous.');
    }

    public function test_the_same_contact_in_two_campaigns_gets_two_identifiers(): void
    {
        $user = $this->signedInTenant();
        $list = $this->listFor($user);
        $contact = $this->eligibleContact($user, $list);

        $first = $this->launchedCampaignFor($user, $list);
        $second = $this->launchedCampaignFor($user, $list);

        $firstId = CampaignRecipient::query()
            ->where('campaign_id', $first->id)
            ->where('contact_id', $contact->id)
            ->value('message_id');

        $secondId = CampaignRecipient::query()
            ->where('campaign_id', $second->id)
            ->where('contact_id', $contact->id)
            ->value('message_id');

        $this->assertNotNull($firstId);
        $this->assertNotNull($secondId);
        $this->assertNotSame(
            $firstId,
            $secondId,
            'Two campaigns to one address are two messages. Sharing an identifier would make a bounce for either indistinguishable.',
        );
    }

    public function test_every_attempt_records_the_identifier_that_was_actually_submitted(): void
    {
        $campaign = $this->runningCampaign(1);

        $this->transport->answerNext(DeliveryOutcome::TemporaryFailure, '451', 'Try later');

        $this->sendUntilEmpty($campaign);

        $recipient = $campaign->recipients()->sole()->fresh();

        $this->assertSame(2, DeliveryAttempt::query()->count());

        foreach ($recipient->deliveryAttempts()->orderBy('attempt_number')->get() as $attempt) {
            $this->assertSame(
                $recipient->message_id,
                $attempt->message_id,
                'An attempt row that cannot say which identifier it used is not an audit trail.',
            );
        }
    }

    public function test_an_attempt_that_never_submitted_records_no_identifier(): void
    {
        $user = $this->signedInTenant();
        $list = $this->listFor($user);
        $contact = $this->eligibleContact($user, $list);

        $campaign = $this->launchedCampaignFor($user, $list);

        // Unsubscribed between the launch and their turn, so the row exists — the
        // campaign's own record of who it was going to contact is part of what it
        // froze — but nothing is ever submitted for it.
        app(SuppressionList::class)->suppress($contact, SuppressionReason::Unsubscribed);

        $this->sendUntilEmpty($campaign);

        $blocked = CampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', CampaignRecipientStatus::Blocked->value)
            ->sole();

        $attempt = $blocked->deliveryAttempts()->sole();

        $this->assertSame(AttemptResult::Blocked, $attempt->result);
        $this->assertNull(
            $attempt->message_id,
            'Nothing went on the wire, so there was no identifier to record.',
        );
    }

    public function test_an_ambiguous_submission_creates_no_second_attempt_and_no_second_identifier(): void
    {
        $campaign = $this->runningCampaign(1);

        $expected = $campaign->recipients()->sole()->message_id;

        // A connection that died without a status code. The policy from the audit
        // stands: not retryable, and the identifier it went out under is the only
        // one that will ever exist for it.
        $this->transport->answering(DeliveryOutcome::Ambiguous);

        $this->sendUntilEmpty($campaign);

        $recipient = $campaign->recipients()->sole()->fresh();

        $this->assertSame(1, $recipient->attempts, 'Resending an unacknowledged message is how one lost receipt becomes two deliveries.');
        $this->assertSame(1, DeliveryAttempt::query()->where('campaign_recipient_id', $recipient->id)->count());
        $this->assertSame(CampaignRecipientStatus::Unknown, $recipient->status);
        $this->assertSame($expected, $recipient->message_id);
    }

    public function test_an_identifier_finds_the_recipient_that_sent_under_it(): void
    {
        $campaign = $this->runningCampaign(2);

        $recipients = $campaign->recipients()->get();
        $wanted = $recipients->first();

        $found = CampaignRecipient::findByMessageId((string) $wanted->message_id);

        $this->assertNotNull($found);
        $this->assertSame($wanted->id, $found->id);
        $this->assertTrue($campaign->is($found->campaign), 'From the identifier, a campaign has to be reachable without a second guess.');
        $this->assertNotNull($found->contact_id);
    }

    public function test_an_identifier_from_something_else_finds_nothing_rather_than_guessing(): void
    {
        $this->runningCampaign(2);

        $this->assertNull(CampaignRecipient::findByMessageId('campaign-'.str_repeat('0', 24).'@example.test'));
        $this->assertNull(CampaignRecipient::findByMessageId(''));
        $this->assertNull(CampaignRecipient::findByMessageId('   '));
    }

    public function test_a_lookup_ignores_the_case_a_provider_reported_it_in(): void
    {
        $campaign = $this->runningCampaign(1);

        $original = (string) $campaign->recipients()->sole()->message_id;

        $shouted = CampaignRecipient::findByMessageId(mb_strtoupper($original));

        $this->assertNotNull($shouted, 'A receiving server may report the header as it received it, and nothing preserves our casing.');
        $this->assertSame($original, $shouted->message_id);
    }

    // ---------------------------------------------------------------------
    // The real submission path
    // ---------------------------------------------------------------------

    public function test_the_production_smtp_class_compiles(): void
    {
        // A regression test for the bug this task's tests exist around: the mail
        // closure both declared a parameter and imported a variable of the same
        // name, which PHP rejects when it compiles the file. Nothing caught it
        // because every campaign test substituted a recording transport, so this
        // class was never loaded by anything at all. A campaign sent through a real
        // account would have fataled on its first message.
        $this->assertTrue(
            class_exists(SmtpMessageTransport::class),
            'The class under test could not be loaded, so nothing below it is being tested either.',
        );

        // And it satisfies the interface the engine depends on, which is where the
        // new identifier parameter would have been missed otherwise.
        $this->assertInstanceOf(MessageTransport::class, $this->realTransport());
    }

    public function test_the_real_transport_puts_the_recipients_identifier_on_the_wire(): void
    {
        $factory = new CapturingMailerFactory($this->app->make('view'));
        $messageId = 'campaign-'.str_repeat('a', 24).'@example.test';

        $result = $this->realTransport($factory)->submit(
            $this->transportDefinition(),
            CampaignMessage::compose('Frozen subject', '<p>Body</p>', 'Body'),
            'alice@example.com',
            'bob@example.com',
            'https://example.test/unsubscribe/token',
            $messageId,
        );

        $this->assertSame(DeliveryOutcome::Accepted, $result->outcome);

        $headers = $factory->lastMessageIdHeaders();

        $this->assertCount(1, $headers, 'Two Message-ID headers means a receiving server picks one we did not choose.');
        $this->assertSame(
            '<'.$messageId.'>',
            $headers[0],
            'Symfony renders an identifier in angle brackets, and what goes on the wire is what a provider quotes back at us.',
        );
    }

    public function test_the_real_transport_sends_the_frozen_snapshot_and_the_required_headers(): void
    {
        $factory = new CapturingMailerFactory($this->app->make('view'));

        $this->realTransport($factory)->submit(
            $this->transportDefinition(),
            CampaignMessage::compose('The frozen subject', '<p>The frozen body</p>', 'The frozen text'),
            'alice@example.com',
            'bob@example.com',
            'https://example.test/unsubscribe/token',
            'campaign-'.str_repeat('b', 24).'@example.test',
        );

        $message = $factory->lastMessage();

        $this->assertNotNull($message);
        $this->assertSame('The frozen subject', $message->getSubject());
        $this->assertSame('<p>The frozen body</p>', trim((string) $message->getHtmlBody()));
        $this->assertSame('The frozen text', trim((string) $message->getTextBody()));
        $this->assertSame(['alice@example.com'], $this->addresses($message->getFrom()));
        $this->assertSame(['bob@example.com'], $this->addresses($message->getTo()));

        $headers = [];

        foreach ($message->getHeaders()->all() as $name => $values) {
            foreach (is_array($values) ? $values : [$values] as $value) {
                $headers[strtolower((string) $name)] = $value->getBodyAsString();
            }
        }

        $this->assertSame('<https://example.test/unsubscribe/token>', $headers['list-unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $headers['list-unsubscribe-post']);
        $this->assertSame('auto-generated', $headers['auto-submitted']);
    }

    public function test_the_real_transport_reports_a_refusal_as_a_permanent_failure(): void
    {
        // `5.0.0` is a refusal of the message itself. `5.1.1` would be a refusal of
        // one mailbox, which the campaign engine treats separately — the distinction is
        // asserted in the classifier tests, not here.
        $factory = (new CapturingMailerFactory($this->app->make('view')))
            ->refusing('Email transport error: 550 5.0.0 Message rejected by policy');

        $result = $this->realTransport($factory)->submit(
            $this->transportDefinition(),
            CampaignMessage::compose('Subject', '<p>Body</p>', 'Body'),
            'alice@example.com',
            'bob@example.com',
            'https://example.test/unsubscribe/token',
            'campaign-'.str_repeat('c', 24).'@example.test',
        );

        $this->assertSame(DeliveryOutcome::PermanentFailure, $result->outcome);
        $this->assertSame('550', $result->code);
        $this->assertFalse($result->attemptResult()->isRetryable());
    }

    public function test_a_timeout_is_not_mistaken_for_a_server_that_refused_the_message(): void
    {
        // The bug this class had, in the exact words Symfony produces. `587` is the
        // port, not a status code, and reading it as one put a timeout into the `5xx`
        // branch — a timeout reported as a refusal, with a code attached to make it
        // look evidenced.
        $factory = (new CapturingMailerFactory($this->app->make('view')))
            ->refusing('Connection to smtp.example.test:587 timed out');

        $result = $this->realTransport($factory)->submit(
            $this->transportDefinition(),
            CampaignMessage::compose('Subject', '<p>Body</p>', 'Body'),
            'alice@example.com',
            'bob@example.com',
            'https://example.test/unsubscribe/token',
            'campaign-'.str_repeat('d', 24).'@example.test',
        );

        $this->assertNull($result->code, 'The port is not a status code, and inventing one would invent evidence.');
        $this->assertSame(DeliveryOutcome::Ambiguous, $result->outcome);
        $this->assertFalse($result->attemptResult()->isRetryable());
        $this->assertNull(
            app(RetryPolicy::class)->delayFor($result->attemptResult(), 1),
            'There is no scheduled retry for an unacknowledged message, so nothing will send it again on a timer.',
        );
    }

    public function test_the_identifier_the_real_transport_reports_is_the_one_it_was_given(): void
    {
        $factory = new CapturingMailerFactory($this->app->make('view'));
        $messageId = 'campaign-'.str_repeat('e', 24).'@example.test';

        $result = $this->realTransport($factory)->submit(
            $this->transportDefinition(),
            CampaignMessage::compose('Subject', '<p>Body</p>', 'Body'),
            'alice@example.com',
            'bob@example.com',
            'https://example.test/unsubscribe/token',
            $messageId,
        );

        $this->assertSame($messageId, $result->messageId);
    }

    public function test_the_generator_produces_something_new_every_time_it_is_asked(): void
    {
        $ids = new LogicalMessageId;

        $first = $ids->generate();
        $second = $ids->generate();

        $this->assertNotSame(
            $first,
            $second,
            'Two logical messages under one identifier would make every future correlation ambiguous rather than wrong.',
        );

        // Nothing attempt-shaped in it. The defect this whole task removes was an
        // identifier that changed when the attempt number did, so a value carrying
        // a visible sequence would be the same defect wearing a different hat.
        $local = explode('@', $first)[0];

        $this->assertStringStartsWith('campaign-', $local);
        $this->assertMatchesRegularExpression('/^campaign-[0-9a-f]{24}$/', $local);
    }

    public function test_the_generator_falls_back_to_a_valid_host_rather_than_emitting_a_bare_at_sign(): void
    {
        config()->set('app.url', 'not a url');

        $id = (new LogicalMessageId)->generate();

        $this->assertStringEndsWith('@localhost', $id);
    }

    /**
     * @param  array<Address>  $addresses
     * @return list<string>
     */
    private function addresses(array $addresses): array
    {
        return array_map(
            static fn (Address $address): string => $address->getAddress(),
            $addresses,
        );
    }

    /**
     * The production transport, with only the socket replaced.
     */
    private function realTransport(?CapturingMailerFactory $factory = null): SmtpMessageTransport
    {
        return new SmtpMessageTransport(
            $factory ?? new CapturingMailerFactory($this->app->make('view')),
            new SmtpFailureClassifier,
        );
    }

    private function transportDefinition(): SmtpTransportDefinition
    {
        return new SmtpTransportDefinition(
            host: 'smtp.example.test',
            port: 587,
            encryption: SmtpEncryption::StartTls,
            authMode: SmtpAuthMode::Password,
            username: 'alice@example.com',
            secret: 'not-a-real-secret',
        );
    }

    // ---------------------------------------------------------------------
    // Arrangement
    // ---------------------------------------------------------------------

    /**
     * A launched campaign over the given list.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function launchedCampaignFor(User $user, ContactList $list, array $overrides = []): Campaign
    {
        $campaign = $this->draftFor($user, array_merge(['list_id' => $list->id], $overrides));

        app(CampaignLauncher::class)->launchNow($campaign);

        return $campaign->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function runningCampaign(int $recipients, array $overrides = []): Campaign
    {
        $user = $this->signedInTenant();
        $list = $this->listFor($user);

        for ($i = 0; $i < $recipients; $i++) {
            $this->eligibleContact($user, $list);
        }

        return $this->launchedCampaignFor($user, $list, $overrides);
    }

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

        return app(CampaignRunner::class)->run($campaign->fresh());
    }

    private function makeOneSendDue(Campaign $campaign): void
    {
        DB::table('campaigns')->where('id', $campaign->id)
            ->update(['next_send_at' => now()->subSecond()]);
    }

    private function makeRetriesDue(Campaign $campaign): void
    {
        DB::table('campaign_recipients')
            ->where('campaign_id', $campaign->id)
            ->update(['next_attempt_at' => now()->subMinute()]);
    }
}

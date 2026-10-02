<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\System\Capabilities\CapabilityRegistry;
use App\Domain\System\Enums\CapabilityStatus;
use App\Domain\System\Enums\CapabilitySubject;
use App\Domain\System\Mail\SmtpCapability;
use App\Domain\System\Mail\SmtpVerification;
use App\Domain\System\Mail\SmtpVerifier;
use App\Domain\System\Settings\SystemSetting;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * SMTP is established by verification, not inferred from configuration.
 *
 * Credentials being present is not evidence that they work, so the capability
 * must stay UNKNOWN until somebody has actually established it — and must say
 * precisely what was proved, because "SMTP available" is otherwise read as
 * "mail arrives".
 */
class SmtpCapabilityTest extends TestCase
{
    public function test_smtp_is_unknown_before_any_verification(): void
    {
        $capability = app(SmtpCapability::class);

        $this->assertNull($capability->latest());
        $this->assertSame(CapabilityStatus::Unknown, $capability->check()->capability);

        $check = $capability->check();
        $this->assertStringContainsString('verify-smtp', implode(' ', $check->remedies));
    }

    public function test_a_non_delivering_mailer_verifies_as_unavailable(): void
    {
        // The suite runs with the array mailer, which discards everything.
        $verification = app(SmtpVerifier::class)->verifyPlatform('ops@example.com');

        $this->assertSame(CapabilityStatus::Unavailable, $verification->status);
        $this->assertFalse($verification->proved(SmtpVerification::STAGE_CONFIGURATION));
        $this->assertStringContainsString('array', $verification->stages[0]['detail']);
    }

    public function test_a_non_delivering_mailer_never_attempts_a_connection(): void
    {
        // Nothing should be dialled when the configuration already proves the
        // mailer cannot deliver.
        $verification = app(SmtpVerifier::class)->verifyPlatform('ops@example.com');

        $this->assertFalse($verification->proved(SmtpVerification::STAGE_CONNECTION));
        $this->assertFalse($verification->proved(SmtpVerification::STAGE_TRANSPORT));
    }

    public function test_verification_is_recorded_and_reported_by_the_capability(): void
    {
        config()->set('mail.default', 'smtp');

        $capability = app(SmtpCapability::class);

        $capability->record(new SmtpVerification(
            CapabilityStatus::Ready,
            [
                ['name' => SmtpVerification::STAGE_CONFIGURATION, 'passed' => true, 'detail' => "mailer 'smtp'"],
                ['name' => SmtpVerification::STAGE_TRANSPORT, 'passed' => true, 'detail' => 'built'],
                ['name' => SmtpVerification::STAGE_CONNECTION, 'passed' => true, 'detail' => 'connected'],
                ['name' => SmtpVerification::STAGE_ACCEPTANCE, 'passed' => true, 'detail' => 'accepted'],
            ],
            'The server accepted a test message. This does not prove recipient delivery.',
            now()->getTimestamp(),
            mailer: 'smtp',
        ));

        $this->assertDatabaseHas('system_settings', ['key' => 'capability.smtp.verification']);

        $check = $capability->check();

        $this->assertSame(CapabilityStatus::Ready, $check->capability);

        // The boundary must be visible in the diagnostic, not just in the code.
        $this->assertStringContainsString('not proved: recipient delivery', $check->detail);
    }

    public function test_a_recorded_failure_reports_unavailable_with_its_reason(): void
    {
        config()->set('mail.default', 'smtp');

        $capability = app(SmtpCapability::class);

        $capability->record(new SmtpVerification(
            CapabilityStatus::Unavailable,
            [['name' => SmtpVerification::STAGE_CONNECTION, 'passed' => false, 'detail' => 'connection refused']],
            'Could not open a connection to the mail server.',
            now()->getTimestamp(),
            'connection refused by mail.example.com:587',
            mailer: 'smtp',
        ));

        $check = $capability->check();

        $this->assertSame(CapabilityStatus::Unavailable, $check->capability);
        $this->assertStringContainsString('connection refused by mail.example.com', implode(' ', $check->remedies));
    }

    public function test_an_old_verification_degrades_rather_than_trusting_forever(): void
    {
        config()->set('mail.default', 'smtp');

        $capability = app(SmtpCapability::class);

        config()->set('sender.capabilities.smtp.fresh_after_seconds', 3600);

        $capability->record(new SmtpVerification(
            CapabilityStatus::Ready,
            [['name' => SmtpVerification::STAGE_ACCEPTANCE, 'passed' => true, 'detail' => 'accepted']],
            'verified',
            now()->subDay()->getTimestamp(),
            mailer: 'smtp',
        ));

        $this->assertSame(CapabilityStatus::Degraded, $capability->check()->capability);
    }

    public function test_verification_can_be_discarded(): void
    {
        $capability = app(SmtpCapability::class);

        $capability->record(new SmtpVerification(
            CapabilityStatus::Unavailable,
            [],
            'broken',
            now()->getTimestamp(),
        ));

        $this->assertNotNull($capability->latest());

        $capability->forget();

        $this->assertNull($capability->latest());
        $this->assertSame(CapabilityStatus::Unknown, $capability->check()->capability);
    }

    public function test_a_verified_smtp_capability_flows_into_the_registry(): void
    {
        config()->set('mail.default', 'smtp');

        app(SmtpCapability::class)->record(new SmtpVerification(
            CapabilityStatus::Ready,
            [['name' => SmtpVerification::STAGE_ACCEPTANCE, 'passed' => true, 'detail' => 'accepted']],
            'verified',
            now()->getTimestamp(),
            mailer: 'smtp',
        ));

        $this->assertSame(
            CapabilityStatus::Ready,
            app(CapabilityRegistry::class)->status(CapabilitySubject::Smtp),
        );
    }

    public function test_a_verification_does_not_survive_a_mailer_change(): void
    {
        $capability = app(SmtpCapability::class);

        config()->set('mail.default', 'smtp');

        $capability->record(new SmtpVerification(
            CapabilityStatus::Ready,
            [['name' => SmtpVerification::STAGE_ACCEPTANCE, 'passed' => true, 'detail' => 'accepted']],
            'verified',
            now()->getTimestamp(),
            mailer: 'smtp',
        ));

        $this->assertSame(CapabilityStatus::Ready, $capability->check()->capability);

        // Verification records what was true when it ran. Reporting READY
        // after the transport changed would be the platform claiming to do
        // something it is not.
        config()->set('mail.default', 'log');

        $check = $capability->check();

        $this->assertSame(CapabilityStatus::Degraded, $check->capability);
        $this->assertStringContainsString('smtp', $check->detail);
        $this->assertStringContainsString('log', $check->detail);
    }

    public function test_a_verification_recorded_without_a_mailer_is_not_trusted(): void
    {
        $capability = app(SmtpCapability::class);

        $capability->record(new SmtpVerification(
            CapabilityStatus::Ready,
            [],
            'verified by an older release that did not record the mailer',
            now()->getTimestamp(),
        ));

        $this->assertSame(CapabilityStatus::Degraded, $capability->check()->capability);
    }

    public function test_ordinary_requests_never_perform_smtp_verification(): void
    {
        // The capability reads recorded evidence. A page view must not open a
        // mail connection, so verification is observable only as a stored row.
        $this->getJson('/health')->assertOk();

        $this->assertSame(0, SystemSetting::query()->where('key', 'capability.smtp.verification')->count());
    }

    public function test_the_verify_command_records_and_can_discard(): void
    {
        $this->artisan('sender:verify-smtp')->assertFailed();

        $this->assertDatabaseHas('system_settings', ['key' => 'capability.smtp.verification']);

        $this->artisan('sender:verify-smtp', ['--forget' => true])->assertSuccessful();

        $this->assertNull(app(SmtpCapability::class)->latest());
    }

    public function test_composing_a_message_is_provable_independently_of_the_transport(): void
    {
        // Composition is an application-level property and must not be
        // conflated with SMTP availability. It works even when the transport
        // discards mail, which is why it is asserted through the built
        // message rather than inferred from the capability.
        Mail::raw('probe', static fn ($message) => $message->to('ops@example.com')->subject('probe'));

        $sent = Mail::mailer()->getSymfonyTransport()->messages();

        $this->assertCount(1, $sent);

        $recipients = array_map(
            static fn ($address) => $address->getAddress(),
            $sent[0]->getOriginalMessage()->getTo(),
        );

        $this->assertSame(['ops@example.com'], $recipients);
        $this->assertSame('probe', $sent[0]->getOriginalMessage()->getSubject());
    }
}

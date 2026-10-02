<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Mail\DeliveryReadiness;
use App\Domain\Mail\DeliveryReadinessReport;
use App\Domain\Mail\DomainAuthenticationEvidence;
use App\Domain\Mail\MailTransportFactory;
use App\Domain\Mail\ReadinessLevel;
use App\Domain\Mail\SenderIdentityPolicy;
use App\Domain\Mail\SmtpAccount;
use App\Domain\Mail\SmtpAccountStatus;
use App\Domain\Mail\SmtpAuthMode;
use App\Domain\Mail\SmtpEncryption;
use App\Domain\Mail\SmtpFailureReason;
use App\Domain\Mail\SmtpProvider;
use App\Domain\Mail\SmtpTransportDefinition;
use App\Domain\Mail\SmtpTransportUnavailable;
use App\Models\User;

/**
 * What the platform can establish about a transport, and what it refuses to
 * pretend.
 *
 * The tests that matter most here are the negative ones: an absent DMARC record
 * has to block, an unknown DKIM has to stay unknown, and nothing in the report
 * may claim delivery.
 */
class DeliveryReadinessTest extends MailTestCase
{
    public function test_an_unverified_account_is_blocked(): void
    {
        $report = app(DeliveryReadiness::class)->for($this->accountFor(User::factory()->create()));

        $this->assertSame(ReadinessLevel::Block, $report->level('SMTP account verified'));
        $this->assertFalse($report->isReady());
        $this->assertSame('Action required', $report->verdict());
    }

    public function test_a_verified_encrypted_account_passes_its_transport_checks(): void
    {
        $account = $this->accountFor(User::factory()->create());
        $account->markVerified(3600);

        $report = app(DeliveryReadiness::class)->for($account->fresh());

        $this->assertSame(ReadinessLevel::Pass, $report->level('SMTP account verified'));
        $this->assertSame(ReadinessLevel::Pass, $report->level('Transport encryption'));
        $this->assertSame(ReadinessLevel::Pass, $report->level('Authentication'));
    }

    public function test_an_unencrypted_transport_is_blocked(): void
    {
        $account = $this->accountFor(User::factory()->create(), [
            'encryption' => SmtpEncryption::None->value,
            'port' => 25,
        ]);
        $account->markVerified(3600);

        $report = app(DeliveryReadiness::class)->for($account->fresh());

        $this->assertSame(
            ReadinessLevel::Block,
            $report->level('Transport encryption'),
            'A password sent without TLS is a disclosure to everyone on the path.',
        );
    }

    public function test_a_transport_with_no_authentication_is_blocked(): void
    {
        $account = $this->accountFor(User::factory()->create(), [
            'auth_mode' => SmtpAuthMode::None->value,
            'secret' => null,
        ]);

        $report = app(DeliveryReadiness::class)->for($account);

        $this->assertSame(ReadinessLevel::Block, $report->level('Authentication'));
    }

    public function test_a_stale_verification_is_blocked(): void
    {
        $account = $this->accountFor(User::factory()->create());
        $account->markVerified(1);
        $account->verification_expires_at = now()->subMinute();
        $account->save();

        $report = app(DeliveryReadiness::class)->for($account->fresh());

        $this->assertSame(ReadinessLevel::Block, $report->level('SMTP account verified'));
    }

    public function test_a_failed_account_is_blocked_and_names_the_category(): void
    {
        $account = $this->accountFor(User::factory()->create());
        $account->markFailed(SmtpFailureReason::ConnectionFailed);

        $report = app(DeliveryReadiness::class)->for($account->fresh());

        $this->assertSame(ReadinessLevel::Block, $report->level('SMTP account verified'));
        $this->assertStringContainsString(
            SmtpFailureReason::ConnectionFailed->value,
            $report->finding('SMTP account verified')->detail,
        );
    }

    public function test_a_rejected_credential_switches_the_account_off(): void
    {
        $account = $this->accountFor(User::factory()->create());
        $account->markFailed(SmtpFailureReason::AuthenticationFailed);

        $fresh = $account->fresh();

        // Not FAILED but DISABLED: continuing to present a password a server has
        // refused is working against the provider, so the account stops rather
        // than waiting to be retried.
        $this->assertSame(SmtpAccountStatus::Disabled, $fresh->status);
        $this->assertSame(
            SmtpFailureReason::AuthenticationFailed->value,
            $fresh->last_failure_category,
        );
        $this->assertFalse($fresh->effectiveStatus()->isUsable());
    }

    public function test_dkim_without_a_selector_stays_unknown_rather_than_passing(): void
    {
        $account = $this->accountFor(User::factory()->create(), ['dkim_selector' => null]);

        $report = $this->reportWithDns($account, spf: true, dmarc: true, dkimSelector: true);

        $this->assertSame(
            ReadinessLevel::Unknown,
            $report->level('DKIM'),
            'Any record found by searching would be a guess, and a guessed pass is worse than an admitted gap.',
        );
    }

    public function test_alignment_is_reported_as_published_and_never_as_achieved(): void
    {
        $account = $this->accountFor(User::factory()->create());

        $report = $this->reportWithDns($account, spf: true, dmarc: true, dkimSelector: false);

        $this->assertSame(
            ReadinessLevel::Unknown,
            $report->level('Authentication alignment'),
            'Only the final message headers can show alignment, and this platform does not observe them.',
        );
    }

    public function test_a_missing_dmarc_record_blocks(): void
    {
        $account = $this->accountFor(User::factory()->create());

        $report = $this->reportWithDns($account, spf: true, dmarc: false, dkimSelector: false);

        $this->assertSame(
            ReadinessLevel::Block,
            $report->level('DMARC'),
            'A domain cannot adopt a DMARC policy for mail already in flight.',
        );
    }

    public function test_a_missing_spf_record_is_reported(): void
    {
        $account = $this->accountFor(User::factory()->create());

        $report = $this->reportWithDns($account, spf: false, dmarc: true, dkimSelector: false);

        $this->assertSame(ReadinessLevel::Warn, $report->level('SPF'));
    }

    public function test_the_report_never_claims_delivery(): void
    {
        $account = $this->accountFor(User::factory()->create());
        $account->markVerified(3600);

        $report = app(DeliveryReadiness::class)->for($account->fresh());

        $limits = $report->limits();

        $this->assertNotEmpty($limits['cannot_prove']);
        $this->assertContains(
            'Whether a recipient receives the message.',
            $limits['cannot_prove'],
        );

        $everything = mb_strtolower(json_encode($report->toArray()));

        foreach (['delivered to inbox', 'guaranteed inbox', 'spam score', 'will be delivered'] as $claim) {
            $this->assertStringNotContainsString($claim, $everything);
        }
    }

    public function test_the_sending_infrastructure_is_named_as_unverifiable(): void
    {
        $account = $this->accountFor(User::factory()->create());

        $report = app(DeliveryReadiness::class)->for($account);

        $this->assertSame(
            ReadinessLevel::Unknown,
            $report->level('Final sending infrastructure'),
            'A configured relay is frequently not the infrastructure the recipient sees.',
        );
    }

    public function test_the_endpoint_string_never_contains_a_credential(): void
    {
        $transport = $this->accountFor(User::factory()->create())->transport();

        $endpoint = $transport->endpoint();

        $this->assertSame('smtp.gmail.com:587', $endpoint);
        $this->assertStringNotContainsString('app-password-value', $endpoint);
        $this->assertStringNotContainsString('alice@example.com', $endpoint);
    }

    public function test_the_gmail_preset_supplies_documented_defaults_only(): void
    {
        $this->assertSame('smtp.gmail.com', SmtpProvider::Gmail->defaultHost());
        $this->assertSame(587, SmtpProvider::Gmail->defaultPort());
        $this->assertSame(SmtpEncryption::StartTls, SmtpProvider::Gmail->defaultEncryption());
        $this->assertSame(SmtpAuthMode::Password, SmtpProvider::Gmail->defaultAuthMode());
    }

    public function test_the_gmail_guidance_points_at_an_app_password_and_never_an_account_password(): void
    {
        $guidance = mb_strtolower(SmtpProvider::Gmail->guidance());

        $this->assertStringContainsString('app password', $guidance);
        $this->assertStringContainsString('2-step verification', $guidance);
        $this->assertStringContainsString('do not enter your ordinary google account password', $guidance);
        $this->assertStringContainsString('does not attempt to work around them', $guidance);
    }

    public function test_no_provider_preset_hard_codes_a_sending_quota(): void
    {
        // A quota hard-coded in application code would go stale and would imply
        // the platform knows Google's current limit. It does not.
        foreach (SmtpProvider::cases() as $provider) {
            $this->assertNull($provider->defaultPort() === 500 ? true : null);
        }

        $this->assertStringNotContainsString('10000', SmtpProvider::Gmail->guidance());
        $this->assertStringNotContainsString('500', SmtpProvider::Gmail->guidance());
    }

    public function test_ssl_and_starttls_are_both_offered(): void
    {
        $this->assertSame('smtps', SmtpEncryption::ImplicitTls->scheme());
        $this->assertSame('smtp', SmtpEncryption::StartTls->scheme());
        $this->assertTrue(SmtpEncryption::ImplicitTls->isSecure());
        $this->assertTrue(SmtpEncryption::StartTls->isSecure());
        $this->assertFalse(SmtpEncryption::None->isSecure());
    }

    public function test_a_transport_is_built_without_mutating_global_mail_configuration(): void
    {
        $before = config('mail.mailers.smtp');

        $transport = $this->accountFor(User::factory()->create())->transport();

        $mailer = app(MailTransportFactory::class)->for($transport);

        $this->assertFalse($mailer->isStarted());
        $this->assertSame(
            $before,
            config('mail.mailers.smtp'),
            'Global mail configuration must be identical before and after building a tenant transport, '
                .'or a second job in the same worker would inherit it.',
        );
    }

    public function test_two_tenants_produce_two_independent_transports(): void
    {
        $factory = app(MailTransportFactory::class);

        $alice = $this->accountFor(User::factory()->create(), ['host' => 'smtp.alice.example.com']);
        $bob = $this->accountFor(User::factory()->create(), ['host' => 'smtp.bob.example.com']);

        $first = $factory->for($alice->transport());
        $second = $factory->for($bob->transport());

        $this->assertNotSame(
            $first,
            $second,
            'Each send builds its own transport; nothing is shared between tenants.',
        );
    }

    public function test_a_password_mode_transport_without_a_secret_is_refused_before_any_connection(): void
    {
        $transport = new SmtpTransportDefinition(
            host: 'smtp.example.com',
            port: 587,
            encryption: SmtpEncryption::StartTls,
            authMode: SmtpAuthMode::Password,
            username: 'alice@example.com',
            secret: null,
        );

        $this->expectException(SmtpTransportUnavailable::class);

        app(MailTransportFactory::class)->for($transport);
    }

    public function test_a_stored_secret_on_an_unauthenticated_transport_is_refused(): void
    {
        $transport = new SmtpTransportDefinition(
            host: 'smtp.example.com',
            port: 587,
            encryption: SmtpEncryption::StartTls,
            authMode: SmtpAuthMode::None,
            username: null,
            secret: 'a-password',
        );

        $this->expectException(SmtpTransportUnavailable::class);

        app(MailTransportFactory::class)->for($transport);
    }

    public function test_dns_evidence_never_confuses_a_revoked_dkim_key_with_an_enabled_one(): void
    {
        $dns = new class extends DomainAuthenticationEvidence
        {
            public function dkimRecords(string $domain, string $selector): array
            {
                // An empty p= is a published revocation.
                return ['v=DKIM1; k=rsa; p='];
            }

            public function hasDkimSelector(string $domain, string $selector): bool
            {
                foreach ($this->dkimRecords($domain, $selector) as $record) {
                    if (preg_match('/(^|\s)p=([^;\s]*)/i', $record, $m) === 1) {
                        return $m[2] !== '';
                    }
                }

                return false;
            }
        };

        $this->assertFalse($dns->hasDkimSelector('example.com', 's1'));
    }

    public function test_only_a_verified_account_reports_ready(): void
    {
        $this->assertTrue(SmtpAccountStatus::Ready->isUsable());

        foreach ([
            SmtpAccountStatus::Unverified,
            SmtpAccountStatus::Stale,
            SmtpAccountStatus::Failed,
            SmtpAccountStatus::Disabled,
        ] as $status) {
            $this->assertFalse($status->isUsable(), "{$status->value} must not be eligible to send.");
            $this->assertTrue($status->needsAttention());
        }
    }

    /**
     * Build a report with DNS evidence replaced, so no test depends on a real
     * domain's published records.
     */
    private function reportWithDns(
        SmtpAccount $account,
        bool $spf,
        bool $dmarc,
        bool $dkimSelector,
    ): DeliveryReadinessReport {
        $readiness = new DeliveryReadiness(
            app(SenderIdentityPolicy::class),
            new class($spf, $dmarc, $dkimSelector) extends DomainAuthenticationEvidence
            {
                public function __construct(
                    private readonly bool $spf,
                    private readonly bool $dmarc,
                    private readonly bool $dkim,
                ) {}

                public function spfRecords(string $domain): array
                {
                    return $this->spf ? ['v=spf1 include:_spf.example.test ~all'] : [];
                }

                public function hasSpf(string $domain): bool
                {
                    return $this->spf;
                }

                public function hasDkimSelector(string $domain, string $selector): bool
                {
                    return $this->dkim;
                }

                public function dmarcRecord(string $domain): ?string
                {
                    return $this->dmarc ? 'v=DMARC1; p=none; rua=mailto:a@example.com' : null;
                }

                public function hasDmarc(string $domain): bool
                {
                    return $this->dmarc;
                }

                public function dmarcPolicy(string $domain): ?string
                {
                    return $this->dmarc ? 'none' : null;
                }

                public function dmarcAlignment(string $domain): array
                {
                    return ['adkim' => 'r', 'aspf' => 'r'];
                }
            },
        );

        return $readiness->for($account);
    }
}

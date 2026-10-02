<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Mail\CampaignMessage;
use App\Domain\Mail\DeliveryReadiness;
use App\Domain\Mail\DomainAuthenticationEvidence;
use App\Domain\Mail\ReadinessLevel;
use App\Domain\Mail\SmtpEncryption;
use App\Domain\Mail\SmtpProvider;
use App\Models\User;

/**
 * Stage 5A additions carried over from the Stage 5B-5D specification:
 * the sending-interval default, reverse-DNS observation, and the message
 * contract.
 *
 * Each asserts the honest answer, because in all three the tempting version is
 * the wrong one — a claimed requirement that is not one, a finding about the
 * wrong host, or a missing text part nobody notices.
 */
class SendingPolicyContractTest extends MailTestCase
{
    public function test_the_default_interval_is_thirty_seconds_and_declares_itself_an_application_default(): void
    {
        $this->assertSame(30, (int) config('sender.sending.minimum_interval_seconds'));

        // The documentation beside the value is load-bearing: a future reader
        // who assumes this is a provider requirement would treat it as a rule to
        // enforce rather than a floor to respect.
        $this->assertStringContainsString(
            'not a Gmail requirement',
            (string) file_get_contents(config_path('sender.php')),
        );
    }

    public function test_one_worker_run_cannot_drain_a_campaign(): void
    {
        $this->assertGreaterThan(
            0,
            (int) config('sender.sending.max_per_run'),
            'A single campaign must not be able to hold a worker open indefinitely.',
        );
    }

    public function test_complaint_thresholds_are_bands_and_not_a_spam_score(): void
    {
        $this->assertSame(0.001, (float) config('sender.sending.complaint_rate.warn_above'));
        $this->assertSame(0.003, (float) config('sender.sending.complaint_rate.block_above'));
        $this->assertLessThan(
            (float) config('sender.sending.complaint_rate.block_above'),
            (float) config('sender.sending.complaint_rate.warn_above'),
        );
    }

    public function test_a_third_party_relay_yields_no_reverse_dns_finding(): void
    {
        foreach ([SmtpProvider::Gmail, SmtpProvider::GoogleWorkspace, SmtpProvider::CPanel] as $provider) {
            $this->assertTrue($provider->isThirdPartyRelay(), "{$provider->value} is a third-party relay.");

            $account = $this->accountFor(User::factory()->create(), [
                'provider' => $provider->value,
            ]);

            $report = app(DeliveryReadiness::class)->for($account);

            // Not a warning. The endpoint IP is definitively not the sending
            // infrastructure, so a warning here would invent a problem on the
            // customer's behalf that they cannot fix.
            $this->assertSame(
                ReadinessLevel::Unknown,
                $report->level('Reverse DNS for the sending address'),
                "{$provider->value} must not report a reverse DNS warning.",
            );
        }
    }

    public function test_a_custom_host_is_treated_as_the_customers_own(): void
    {
        $this->assertFalse(SmtpProvider::Custom->isThirdPartyRelay());

        $account = $this->accountFor(User::factory()->create(), [
            'provider' => SmtpProvider::Custom->value,
            'host' => 'mail.example.test',
        ]);

        // Unresolvable here, so unobserved rather than failed: an unknown fact
        // must not become a warning.
        $this->assertSame(
            ReadinessLevel::Unknown,
            app(DeliveryReadiness::class)->for($account)->level('Reverse DNS for the sending address'),
        );
    }

    public function test_reverse_dns_of_an_absent_ptr_is_reported_as_absent_not_as_the_address(): void
    {
        $dns = new DomainAuthenticationEvidence;

        // 192.0.2.0/24 is reserved for documentation, so it never has a PTR.
        $this->assertNull($dns->reverseName('192.0.2.55'));
    }

    public function test_an_html_only_message_gets_a_plain_text_part(): void
    {
        $message = CampaignMessage::compose('Hello', '<p>First paragraph.</p><p>Second paragraph.</p>');

        $this->assertSame('alternative', $message->structure());
        $this->assertTrue($message->hasPlainTextAlternative());
        $this->assertStringContainsString('First paragraph.', (string) $message->text);
        $this->assertStringContainsString('Second paragraph.', (string) $message->text);
    }

    public function test_a_supplied_text_body_is_never_overwritten_by_a_generated_one(): void
    {
        $message = CampaignMessage::compose('Hello', '<p>Ignored body</p>', 'The body I actually wrote.');

        $this->assertSame('The body I actually wrote.', $message->text);
    }

    public function test_plain_text_generation_drops_scripts_styles_and_decodes_entities(): void
    {
        $text = CampaignMessage::plainTextFrom(
            '<style>p{color:red}</style><script>alert(1)</script>'
            .'<p>Caf&eacute; &amp; more</p>'
        );

        $this->assertNotNull($text);
        $this->assertStringNotContainsString('alert(1)', $text);
        $this->assertStringNotContainsString('color:red', $text);
        $this->assertStringContainsString('Café & more', $text);
    }

    public function test_a_text_only_message_sends_as_plain(): void
    {
        $message = CampaignMessage::compose('Hello', null, 'Just words.');

        $this->assertSame('plain', $message->structure());
        $this->assertCount(1, $message->parts());
        $this->assertSame('text/plain', $message->parts()[0]['contentType']);
    }

    public function test_a_message_with_no_body_is_not_deliverable(): void
    {
        $message = CampaignMessage::compose('Hello');

        $this->assertTrue($message->isEmpty());
        $this->assertFalse(
            $message->hasDeliverableBody(),
            'A campaign with no body must fail preflight rather than send an empty message.',
        );
        $this->assertSame([], $message->parts());
    }

    public function test_an_html_message_with_no_readable_text_still_reports_no_alternative(): void
    {
        // Markup that yields nothing legible must not be reported as having a
        // text alternative, or the preflight check is satisfied by nothing.
        $message = CampaignMessage::compose('Hello', '<div><span>&nbsp;</span></div>');

        $this->assertFalse($message->hasPlainTextAlternative());
    }

    public function test_preview_comes_from_whichever_part_exists(): void
    {
        $this->assertStringContainsString(
            'Body',
            CampaignMessage::compose('s', '<p>Body</p>')->preview(),
        );

        $this->assertStringContainsString(
            'Body',
            CampaignMessage::compose('s', null, 'Body')->preview(),
        );
    }

    public function test_transport_encryption_remains_a_blocking_check(): void
    {
        // Regression guard on the separation the specification insists on: an
        // unencrypted transport is reachable and still not eligible to send.
        $account = $this->accountFor(User::factory()->create(), [
            'encryption' => SmtpEncryption::None->value,
        ]);
        $account->markVerified(3600);

        $report = app(DeliveryReadiness::class)->for($account->fresh());

        $this->assertSame(ReadinessLevel::Block, $report->level('Transport encryption'));
        $this->assertSame(
            ReadinessLevel::Pass,
            $report->level('SMTP account verified'),
            'The server was reached; reachability and safe sending are different answers.',
        );
    }
}

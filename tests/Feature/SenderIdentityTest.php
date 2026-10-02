<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Mail\DeliveryReadiness;
use App\Domain\Mail\ReadinessLevel;
use App\Domain\Mail\SenderIdentityPolicy;
use App\Domain\Mail\SmtpAuthMode;
use App\Domain\Mail\SmtpEncryption;
use App\Domain\Mail\SmtpTransportDefinition;
use App\Models\User;

/**
 * The From address must be one the transport authenticated as.
 *
 * Anything looser makes this host an open relay for other people's identities:
 * the server accepted a login as `alice@example.com`, and the message claims to
 * be from `attacker@example.com`. The tenant's provider would carry it and the
 * tenant would answer for a complaint they did not make.
 */
class SenderIdentityTest extends MailTestCase
{
    public function test_the_authenticated_address_may_send(): void
    {
        $transport = $this->transport('alice@example.com');

        $this->assertTrue(app(SenderIdentityPolicy::class)->allows($transport, 'alice@example.com'));
        $this->assertTrue(
            app(SenderIdentityPolicy::class)->allows($transport, 'ALICE@example.com'),
            'Address comparison is case-insensitive, as the domain is.',
        );
    }

    public function test_a_different_from_address_is_blocked(): void
    {
        $transport = $this->transport('alice@example.com');

        $this->assertFalse(app(SenderIdentityPolicy::class)->allows($transport, 'attacker@example.com'));
        $this->assertStringContainsString(
            'does not match the authenticated SMTP username',
            app(SenderIdentityPolicy::class)->explain($transport, 'attacker@example.com'),
        );
    }

    public function test_a_gmail_transport_may_not_send_as_an_unrelated_address(): void
    {
        $transport = $this->transport('alice@gmail.com');

        $this->assertFalse(
            app(SenderIdentityPolicy::class)->allows($transport, 'random@yahoo.com'),
            'A Gmail account must not borrow another provider\'s identity.',
        );
        $this->assertFalse(
            app(SenderIdentityPolicy::class)->allows($transport, 'alice@gmail.com.evil.test'),
            'A suffix on the authenticated address is a different address.',
        );
    }

    public function test_a_transport_with_no_username_has_no_identity_to_send_as(): void
    {
        $transport = new SmtpTransportDefinition(
            host: 'mail.example.com',
            port: 587,
            encryption: SmtpEncryption::StartTls,
            authMode: SmtpAuthMode::None,
            username: null,
            secret: null,
        );

        $this->assertFalse(app(SenderIdentityPolicy::class)->allows($transport, 'anyone@example.com'));
    }

    public function test_an_invalid_from_address_is_refused(): void
    {
        $policy = app(SenderIdentityPolicy::class);

        foreach (['', 'not-an-address', 'a@b', "alice@example.com\r\nBcc: x@y.test"] as $bad) {
            $this->assertFalse(
                $policy->allows($this->transport('alice@example.com'), $bad),
                "Refusing From address '{$bad}'",
            );
        }
    }

    public function test_reply_to_is_validated_but_may_differ_from_the_from_address(): void
    {
        $policy = app(SenderIdentityPolicy::class);

        $this->assertTrue($policy->allowsReplyTo('support@example.com'));
        $this->assertTrue($policy->allowsReplyTo(null));
        $this->assertFalse($policy->allowsReplyTo('not-an-address'));

        // Reply-To routes replies; it does not claim authorship, so a support
        // address is legitimate even though the sender is somebody else.
        $this->assertNotSame(
            'support@example.com',
            $this->transport('alice@example.com')->permittedFromAddress(),
        );
    }

    public function test_a_mismatched_from_address_blocks_readiness(): void
    {
        $account = $this->accountFor(User::factory()->create(), [
            'username' => 'alice@example.com',
            'from_address' => 'attacker@example.com',
        ]);

        $report = app(DeliveryReadiness::class)->for($account);

        $this->assertSame(ReadinessLevel::Block, $report->level('From address'));
        $this->assertFalse($report->isReady());
    }

    public function test_a_matching_from_address_passes_the_identity_check(): void
    {
        $account = $this->accountFor(User::factory()->create());

        $report = app(DeliveryReadiness::class)->for($account);

        $this->assertSame(ReadinessLevel::Pass, $report->level('From address'));
        $this->assertSame(ReadinessLevel::Pass, $report->level('Reply-To'));
    }

    public function test_a_form_submitting_a_mismatched_from_address_is_refused(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('account.smtp.store'), [
            'label' => 'Spoof attempt',
            'provider' => 'custom',
            'host' => 'mail.example.com',
            'port' => 587,
            'encryption' => 'starttls',
            'auth_mode' => 'password',
            'username' => 'alice@example.com',
            'secret' => 'a-password',
            'from_address' => 'attacker@example.com',
        ]);

        $response->assertSessionHasErrors('from_address');
        $this->assertDatabaseCount('smtp_accounts', 0);
    }

    public function test_a_gmail_account_is_bound_to_its_own_address(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('account.smtp.store'), [
            'label' => 'Gmail',
            'provider' => 'gmail',
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'encryption' => 'starttls',
            'auth_mode' => 'password',
            'username' => 'alice@gmail.com',
            'secret' => 'app-password',
            'from_address' => 'someone.else@gmail.com',
        ])->assertSessionHasErrors('from_address');

        $this->assertDatabaseCount('smtp_accounts', 0);
    }

    private function transport(string $username): SmtpTransportDefinition
    {
        return new SmtpTransportDefinition(
            host: 'smtp.example.com',
            port: 587,
            encryption: SmtpEncryption::StartTls,
            authMode: SmtpAuthMode::Password,
            username: $username,
            secret: 'a-password',
        );
    }
}

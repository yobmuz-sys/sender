<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Mail\SmtpAccount;
use App\Domain\Mail\SmtpAccountStatus;
use App\Domain\Mail\SmtpAuthMode;
use App\Domain\Mail\SmtpEncryption;
use App\Domain\Mail\SmtpManagementMode;
use App\Domain\Mail\SmtpProvider;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * An SMTP credential must be recoverable by the transport and by nobody else.
 *
 * Each of these asserts a different escape route, because encryption alone does
 * not close them: a plaintext column, a model that serialises its own secret, a
 * view that echoes a form field, a queue payload, a log line.
 */
class SmtpCredentialSecurityTest extends MailTestCase
{
    public function test_the_stored_credential_is_ciphertext(): void
    {
        $account = $this->accountFor(User::factory()->create());

        $raw = $this->rawSecret($account);

        $this->assertNotNull($raw);
        $this->assertStringNotContainsString('app-password-value', $raw);
        $this->assertNotSame('app-password-value', $raw);

        // And it is not a reversible encoding of the plaintext.
        $this->assertStringNotContainsString(base64_encode('app-password-value'), $raw);
    }

    public function test_the_raw_column_never_holds_the_plaintext_across_every_writable_path(): void
    {
        $user = User::factory()->create();
        $account = $this->accountFor($user);

        $rows = DB::table('smtp_accounts')->get();

        $this->assertStringNotContainsString('app-password-value', json_encode($rows));
    }

    public function test_the_transport_can_still_recover_the_plaintext(): void
    {
        $account = $this->accountFor(User::factory()->create());

        // The point of the exercise: encryption must not make the credential
        // unusable, only hard to read by accident.
        $this->assertSame('app-password-value', $account->transport()->secret());
    }

    public function test_the_model_hides_the_secret_from_serialisation(): void
    {
        $account = $this->accountFor(User::factory()->create());

        $serialised = $account->toArray();

        $this->assertArrayNotHasKey('secret', $serialised);
        $this->assertStringNotContainsString('app-password-value', json_encode($serialised));
    }

    public function test_the_secret_is_absent_from_a_json_response(): void
    {
        $user = User::factory()->create();
        $account = $this->accountFor($user);

        $response = $this->actingAs($user)
            ->getJson(route('account.smtp.show', $account));

        $response->assertOk();
        $this->assertStringNotContainsString('app-password-value', $response->getContent());
    }

    public function test_no_log_line_records_the_plaintext(): void
    {
        $account = $this->accountFor(User::factory()->create());

        $messages = [];

        Log::listen(function ($message) use (&$messages): void {
            $messages[] = json_encode($message->context);
        });

        $account->configurationFingerprint();
        $account->toArray();
        $account->transport()->endpoint();
        $account->effectiveStatus();

        $joined = implode("\n", $messages);

        $this->assertStringNotContainsString('app-password-value', $joined);
    }

    public function test_the_password_is_never_rendered_into_a_form(): void
    {
        $user = User::factory()->create();
        $account = $this->accountFor($user);

        foreach ([
            route('account.smtp.show', $account),
            route('account.smtp.edit', $account),
            route('account.smtp.index'),
        ] as $url) {
            $response = $this->actingAs($user)->get($url);

            $response->assertOk();
            $this->assertStringNotContainsString('app-password-value', $response->getContent(), $url);
        }
    }

    public function test_a_failed_submission_does_not_echo_the_password_back(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('account.smtp.store'), [
            'label' => 'Broken',
            'provider' => SmtpProvider::Custom->value,
            'host' => 'smtp.example.com',
            'port' => 587,
            'encryption' => SmtpEncryption::StartTls->value,
            'auth_mode' => SmtpAuthMode::Password->value,
            'username' => 'alice@example.com',
            'secret' => 'super-secret-attempt',
            // Missing from address, so validation must fail.
            'from_address' => '',
        ]);

        $response->assertSessionHasErrors('from_address');

        $this->assertStringNotContainsString(
            'super-secret-attempt',
            json_encode(session()->all()),
        );
    }

    public function test_changing_the_configuration_invalidates_the_verification(): void
    {
        $account = $this->accountFor(User::factory()->create());
        $account->markVerified(3600);

        $this->assertSame(SmtpAccountStatus::Ready, $account->fresh()->effectiveStatus());

        // A different password is a different transport, and the earlier proof
        // says nothing about it.
        $rotated = $this->submit($account, ['secret' => 'rotated-password']);

        $this->assertSame(SmtpAccountStatus::Unverified, $rotated->effectiveStatus());
        $this->assertNull($rotated->verified_at);
        $this->assertNull($rotated->configuration_fingerprint);
    }

    public function test_every_transport_field_drop_its_verification(): void
    {
        foreach ([
            'host' => 'relay.example.com',
            'port' => 465,
            'encryption' => SmtpEncryption::ImplicitTls->value,
            'username' => 'other@example.com',
            'from_address' => 'other@example.com',
        ] as $field => $value) {
            $account = $this->accountFor(User::factory()->create());
            $account->markVerified(3600);

            $changed = $this->submit($account, [$field => $value]);

            $this->assertSame(
                SmtpAccountStatus::Unverified,
                $changed->effectiveStatus(),
                "Changing {$field} must invalidate a verification earned against the old value.",
            );
        }
    }

    public function test_changing_only_the_label_keeps_the_verification(): void
    {
        $account = $this->accountFor(User::factory()->create());
        $account->markVerified(3600);

        $renamed = $this->submit($account, ['label' => 'Renamed']);

        $this->assertSame(
            SmtpAccountStatus::Ready,
            $renamed->effectiveStatus(),
            'A renamed transport has the same configuration, so its verification still describes it.',
        );
    }

    public function test_a_blank_password_keeps_the_stored_credential(): void
    {
        $account = $this->accountFor(User::factory()->create());

        $updated = $this->submit($account, ['label' => 'Same password']);

        $this->assertSame('app-password-value', $updated->transport()->secret());
    }

    public function test_a_verification_expires_into_stale(): void
    {
        $account = $this->accountFor(User::factory()->create());
        $account->markVerified(1);

        $account->verification_expires_at = now()->subSecond();
        $account->save();

        $this->assertSame(
            SmtpAccountStatus::Stale,
            $account->fresh()->effectiveStatus(),
            'A stored READY that is not checked would outlive the evidence behind it.',
        );
        $this->assertFalse($account->fresh()->effectiveStatus()->isUsable());
    }

    public function test_an_account_starts_unverified_and_never_ready_by_default(): void
    {
        $account = SmtpAccount::query()->create([
            'user_id' => User::factory()->create()->getKey(),
            'label' => 'Fresh',
            'provider' => SmtpProvider::Custom->value,
            'management_mode' => SmtpManagementMode::UserManaged->value,
            'host' => 'mail.example.com',
            'port' => 465,
            'encryption' => SmtpEncryption::ImplicitTls->value,
            'auth_mode' => SmtpAuthMode::Password->value,
            'username' => 'bob@example.com',
            'from_address' => 'bob@example.com',
        ]);

        $this->assertSame(SmtpAccountStatus::Unverified, $account->effectiveStatus());
        $this->assertFalse($account->effectiveStatus()->isUsable());
    }
}

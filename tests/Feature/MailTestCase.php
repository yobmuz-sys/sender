<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Mail\SmtpAccount;
use App\Domain\Mail\SmtpAccountWriter;
use App\Domain\Mail\SmtpAuthMode;
use App\Domain\Mail\SmtpEncryption;
use App\Domain\Mail\SmtpManagementMode;
use App\Domain\Mail\SmtpProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Base class for the Stage 5A mail tests.
 *
 * Builds accounts directly rather than through the HTTP form, because most of
 * these tests are about what the *stored* row guarantees — the encrypted column,
 * the fingerprint, the lifecycle — and driving them through a form would couple
 * every assertion to the validation rules instead.
 */
abstract class MailTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function accountFor(User $user, array $overrides = []): SmtpAccount
    {
        return SmtpAccount::query()->create(array_merge([
            'user_id' => $user->getKey(),
            'label' => 'Primary',
            'provider' => SmtpProvider::Gmail,
            'management_mode' => SmtpManagementMode::UserManaged,
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'encryption' => SmtpEncryption::StartTls,
            'auth_mode' => SmtpAuthMode::Password,
            'username' => 'alice@example.com',
            'secret' => 'app-password-value',
            'from_address' => 'alice@example.com',
            'from_name' => 'Alice',
            'reply_to' => null,
            'dkim_selector' => null,
        ], $overrides));
    }

    /**
     * The literal column value, bypassing every cast.
     *
     * Read through the query builder rather than the model: an Eloquent `value()`
     * still applies casts, so asking the model for its own ciphertext would
     * decrypt it again and prove nothing about what is on disk.
     */
    protected function rawSecret(SmtpAccount $account): ?string
    {
        return DB::table('smtp_accounts')
            ->where('id', $account->getKey())
            ->value('secret');
    }

    /**
     * Apply a form submission through the real writer.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function submit(SmtpAccount $account, array $overrides = []): SmtpAccount
    {
        $request = Request::create('/', 'POST', array_merge([
            'label' => $account->label,
            'provider' => $account->provider->value,
            'management_mode' => $account->management_mode->value,
            'host' => $account->host,
            'port' => $account->port,
            'encryption' => $account->encryption->value,
            'auth_mode' => $account->auth_mode->value,
            'username' => $account->username,
            'from_address' => $account->from_address,
            'from_name' => $account->from_name,
            'reply_to' => $account->reply_to,
            'dkim_selector' => $account->dkim_selector,
            // Deliberately absent: a form that never received the stored secret
            // cannot submit one, which is what "blank means keep" relies on.
        ], $overrides));

        return app(SmtpAccountWriter::class)->update($account, $request, $account->management_mode)->fresh();
    }
}

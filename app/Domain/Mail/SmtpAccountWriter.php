<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Applies a submitted SMTP form to a stored account.
 *
 * Two rules live here because both are easy to get subtly wrong when repeated in
 * two controllers.
 *
 * **A secret is written only when one was submitted.** An edit that changes the
 * label must not blank the password, and a form that never received the existing
 * secret cannot submit one — so "blank means leave it alone" is the only
 * behaviour that lets a partial edit work at all.
 *
 * **A change to the transport drops the verification.** A `READY` account is
 * evidence about the configuration it was earned against. If the host, port,
 * encryption, username, password or From address changed, that evidence
 * describes something that no longer exists, and leaving it in place would let a
 * tenant send through a server nobody has ever reached. The comparison is by
 * {@see SmtpAccount::configurationFingerprint()} rather than by which fields were
 * submitted, so a new value that happens to equal the old one correctly keeps the
 * verification — an unchanged configuration has not stopped being proved.
 */
final class SmtpAccountWriter
{
    /**
     * Create an account for a user.
     */
    public function create(Request $request, User $user, SmtpManagementMode $mode): SmtpAccount
    {
        $account = new SmtpAccount(['user_id' => $user->getKey()]);

        $this->apply($account, $request, $mode, creating: true);

        return $account;
    }

    /**
     * Apply a submission to an existing account.
     */
    public function update(SmtpAccount $account, Request $request, SmtpManagementMode $mode): SmtpAccount
    {
        $this->apply($account, $request, $mode, creating: false);

        return $account;
    }

    /**
     * Assign an existing account to a user, or take it away.
     *
     * Moves the row rather than copying it. The credential belongs to the
     * transport, not to the tenancy, and duplicating it to effect a reassignment
     * would leave two rows holding the same encrypted secret — and two rows that
     * could then be verified, disabled and re-enabled independently.
     */
    public function assignTo(SmtpAccount $account, User $user): SmtpAccount
    {
        $account->forceFill(['user_id' => $user->getKey()])->save();

        return $account;
    }

    public function setStatus(SmtpAccount $account, SmtpAccountStatus $status): SmtpAccount
    {
        // Switching an account back on does not restore a verification. The
        // reason it was disabled may still hold, and re-enabling is a decision to
        // use it again — not evidence that it works.
        $account->forceFill([
            'status' => $status->value,
            'verified_at' => null,
            'verification_expires_at' => null,
            'configuration_fingerprint' => null,
        ])->save();

        return $account;
    }

    private function apply(SmtpAccount $account, Request $request, SmtpManagementMode $mode, bool $creating): void
    {
        $before = $creating ? null : $account->configurationFingerprint();

        $account->fill([
            'label' => (string) $request->input('label'),
            'provider' => $request->input('provider'),
            'management_mode' => $mode->value,
            'host' => trim((string) $request->input('host')),
            'port' => (int) $request->input('port'),
            'encryption' => $request->input('encryption'),
            'auth_mode' => $request->input('auth_mode'),
            'username' => filled($request->input('username')) ? trim((string) $request->input('username')) : null,
            'from_address' => mb_strtolower(trim((string) $request->input('from_address'))),
            'from_name' => filled($request->input('from_name')) ? trim((string) $request->input('from_name')) : null,
            'reply_to' => filled($request->input('reply_to')) ? mb_strtolower(trim((string) $request->input('reply_to'))) : null,
            'dkim_selector' => filled($request->input('dkim_selector')) ? trim((string) $request->input('dkim_selector')) : null,
        ]);

        // Only written when supplied. See the class note.
        if (filled($request->input('secret'))) {
            $account->secret = (string) $request->input('secret');
        }

        if ($creating) {
            $account->status = SmtpAccountStatus::Unverified;
            $account->save();

            return;
        }

        $account->save();

        $after = $account->configurationFingerprint();

        if ($before !== null && $before !== $after) {
            // The configuration this verification was earned against is gone.
            $account->forceFill([
                'status' => SmtpAccountStatus::Unverified->value,
                'verified_at' => null,
                'verification_expires_at' => null,
                'configuration_fingerprint' => null,
            ])->save();
        }
    }
}

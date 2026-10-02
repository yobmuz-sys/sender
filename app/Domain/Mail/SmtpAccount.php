<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One SMTP transport belonging to one tenant.
 *
 * Holds either a tenant's own credentials or credentials an operator supplied
 * for them; `management_mode` is which, and it is the only difference between
 * the two. There is deliberately no second table.
 *
 * The secret is stored encrypted through the model's cast, so it cannot be read
 * by accident: an accidental `$account->toArray()`, a `dd()`, an exception that
 * dumps the model, or a queued job serialising it will all produce the
 * ciphertext rather than a mailbox password. Reading the plaintext requires
 * calling {@see SmtpSecret::reveal()} on the cast accessor, which is visible at
 * the call site and therefore reviewable.
 *
 * @property-read string $label
 * @property SmtpProvider $provider
 * @property SmtpManagementMode $management_mode
 * @property SmtpEncryption $encryption
 * @property SmtpAuthMode $auth_mode
 * @property SmtpAccountStatus $status
 */
class SmtpAccount extends Model
{
    protected $fillable = [
        'user_id',
        'label',
        'provider',
        'management_mode',
        'host',
        'port',
        'encryption',
        'auth_mode',
        'username',
        'secret',
        'from_address',
        'from_name',
        'reply_to',
        'dkim_selector',
        'status',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        // Never serialised. A queued job receives an id; a log line receives
        // an account label. Neither needs the credential, and both persist.
        'secret',
    ];

    protected function casts(): array
    {
        return [
            'provider' => SmtpProvider::class,
            'management_mode' => SmtpManagementMode::class,
            'encryption' => SmtpEncryption::class,
            'auth_mode' => SmtpAuthMode::class,
            'status' => SmtpAccountStatus::class,
            'port' => 'integer',
            'secret' => EncryptedSecretCast::class,
            'verified_at' => 'datetime',
            'verification_expires_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    protected $attributes = [
        // Never READY by default. An account that has never been dialled is
        // UNVERIFIED, and the readiness service depends on that distinction.
        'status' => 'unverified',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * The transport parameters for this account.
     *
     * @see SmtpTransportDefinition for why these are passed around rather than
     *      read from configuration
     */
    public function transport(): SmtpTransportDefinition
    {
        return new SmtpTransportDefinition(
            host: (string) $this->host,
            port: (int) $this->port,
            encryption: $this->encryption,
            authMode: $this->auth_mode,
            username: $this->username,
            secret: $this->secret?->reveal(),
            identifier: 'smtp:'.$this->id,
            localDomain: config('mail.mailers.smtp.local_domain'),
            timeoutSeconds: (int) config('sender.smtp.timeout_seconds', 10),
        );
    }

    /**
     * Whether the owning user may change this account.
     */
    public function ownerMayEdit(): bool
    {
        return $this->management_mode->ownerMayEdit();
    }

    /**
     * Whether a credential is stored at all.
     *
     * Distinct from being able to read it: this answers "has this account ever
     * had a password", which decides whether the edit form should ask for one.
     */
    public function hasSecret(): bool
    {
        return $this->secret !== null && $this->secret->reveal() !== null;
    }

    /**
     * A value identifying the transport configuration, without the secret.
     *
     * A verification is only evidence about the configuration it was taken
     * against. Comparing this fingerprint is how a stored `READY` survives an
     * unrelated edit (a label) while not surviving a changed host, port,
     * encryption mode, username, sender address or credential.
     *
     * The secret is included as a digest rather than omitted: a rotated password
     * invalidates the verification just as a changed host does, and hashing is
     * what makes that comparable without storing the password twice in a
     * readable form.
     */
    public function configurationFingerprint(): string
    {
        $secret = $this->secret?->reveal();

        return hash('sha256', implode('|', [
            (string) $this->host,
            (string) $this->port,
            $this->encryption->value,
            $this->auth_mode->value,
            (string) $this->username,
            mb_strtolower(trim((string) $this->from_address)),
            $secret === null ? '' : hash('sha256', $secret),
        ]));
    }

    /**
     * Mark this account verified, and record what was proved.
     */
    public function markVerified(int $freshAfterSeconds): void
    {
        $this->forceFill([
            'status' => SmtpAccountStatus::Ready->value,
            'verified_at' => now(),
            'verification_expires_at' => now()->addSeconds($freshAfterSeconds),
            'configuration_fingerprint' => $this->configurationFingerprint(),
            'last_failure_category' => null,
            'last_failure_at' => null,
        ])->save();
    }

    /**
     * Record a verification failure.
     *
     * A transport that rejected its credentials is switched off rather than left
     * retryable: continuing to present a password a server has refused is the
     * platform working against the provider's wishes, and providers rate-limit
     * or block accounts for exactly that.
     */
    public function markFailed(SmtpFailureReason $reason): void
    {
        $this->forceFill([
            'status' => $reason->shouldPauseAccount()
                ? SmtpAccountStatus::Disabled->value
                : SmtpAccountStatus::Failed->value,
            'last_failure_category' => $reason->value,
            'last_failure_at' => now(),
        ])->save();
    }

    /**
     * Recompute the effective status from the stored verification.
     *
     * A verification ages. Rather than persisting a status that silently becomes
     * wrong, the expiry is checked whenever the account is read, so `STALE`
     * cannot be avoided by not looking.
     */
    public function effectiveStatus(): SmtpAccountStatus
    {
        if ($this->status === SmtpAccountStatus::Ready
            && $this->verification_expires_at !== null
            && $this->verification_expires_at->isPast()) {
            return SmtpAccountStatus::Stale;
        }

        return $this->status;
    }
}

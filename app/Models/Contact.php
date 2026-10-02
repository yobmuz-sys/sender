<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audience\ValidationMethod;
use App\Domain\Audience\ValidationReason;
use App\Domain\Audience\ValidationStatus;
use App\Domain\Mail\SmtpAccount;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The canonical email identity for a tenant.
 *
 * Before Stage 5B an address existed only as a row belonging to one extraction,
 * so the same person found on ten pages was ten rows with nothing to say they
 * were the same person — and therefore nowhere to record a consent, a
 * suppression or a validation once and have it apply everywhere.
 *
 * The uniqueness that makes this work is `unique(user_id, normalized_email)`,
 * and it is enforced by the database rather than by an existence check in PHP.
 * That distinction is not pedantry: a check-then-insert leaves a window in which
 * two workers resolving the same address at the same moment both see nothing and
 * both insert. Under one active task per user that window is narrow, but a
 * paste of ten thousand addresses walked in batches is exactly the shape of work
 * that walks through it.
 *
 * Per tenant, not global. The same address may legitimately belong to two
 * accounts — a consultant and the client whose list they hold — and one shared
 * row would leak one customer's consent record and suppression list into
 * another's.
 *
 * The validation columns hold the *latest* overall state. An extraction result
 * keeps its own snapshot of what the check found during that task, so a report
 * written today does not silently change next month because somebody
 * revalidated the address.
 */
class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'email',
        'normalized_email',
        'validation_status',
        'validation_reason',
        'validation_method',
        'validated_at',
        'validation_expires_at',
        'last_smtp_code',
        'last_enhanced_code',
        'is_catch_all',
    ];

    /**
     * A contact is never active until something has made it so.
     *
     * `unknown` with `not_validated` is a real state and not a placeholder: it
     * says "we have not looked", which is a different fact from "we looked and
     * could not tell". Collapsing the two would let a report claim an address
     * was checked when it never was.
     */
    protected $attributes = [
        'validation_status' => 'unknown',
        'validation_reason' => 'not_validated',
        'validation_method' => 'none',
        'is_catch_all' => false,
    ];

    protected function casts(): array
    {
        return [
            'validation_status' => ValidationStatus::class,
            'validation_reason' => ValidationReason::class,
            'validation_method' => ValidationMethod::class,
            'validated_at' => 'datetime',
            'validation_expires_at' => 'datetime',
            'last_smtp_code' => 'integer',
            'is_catch_all' => 'boolean',
        ];
    }

    protected static function newFactory(): ContactFactory
    {
        return ContactFactory::new();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Every extraction this address was found by.
     *
     * The provenance edge. A contact is one person; the rows pointing at it are
     * the ten pages and the pasted file that mention them, which is what makes
     * "where did this address come from" answerable without duplicating the
     * contact per source.
     */
    public function extractionResults(): HasMany
    {
        return $this->hasMany(ExtractionResult::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(ContactConsent::class);
    }

    /**
     * The tenant's suppression row, if there is one.
     *
     * At most one per contact per tenant, so this is read with `first()` on every
     * eligibility query and a missing row is the normal case rather than an
     * exceptional one.
     */
    public function suppression(): HasMany
    {
        return $this->hasMany(Suppression::class);
    }

    public function listMemberships(): HasMany
    {
        return $this->hasMany(ContactListMembership::class);
    }

    /**
     * Restrict a query to one tenant.
     *
     * The tenancy idiom in this codebase, matching {@see SmtpAccount::scopeOwnedBy()}.
     * Written as a scope rather than left to each caller so that "forgot to
     * scope it" is a visible omission at the call site rather than a silent one.
     */
    public function scopeOwnedBy($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Whether validation has produced a result that is still current.
     *
     * A contact whose evidence has expired is treated as unchecked rather than
     * as whatever it used to be. Presenting a month-old acceptance as a current
     * one is a claim the platform cannot support, and it is the failure mode a
     * cache with an expiry exists to prevent.
     */
    public function hasCurrentValidation(): bool
    {
        return $this->validated_at !== null
            && $this->validation_expires_at !== null
            && $this->validation_expires_at->isFuture();
    }
}

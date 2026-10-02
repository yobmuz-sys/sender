<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audience\ConsentLedger;
use App\Domain\Audience\ConsentSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded piece of evidence that a recipient agreed to be contacted.
 *
 * A row rather than a boolean on the contact, and that is the whole design. A
 * boolean answers "may we send?" while recording nothing about *why*, which makes
 * the two questions that actually arise unanswerable: how did this person end up
 * on the list, and is that permission still good?
 *
 * Consent is cumulative rather than a single state. Somebody who signed up in
 * 2024 and clicked a confirmation link in 2026 has two rows and this platform
 * keeps both. The contact's *current* status is derived from them — see
 * {@see ConsentLedger} — and the history is what makes the
 * answer auditable.
 *
 * `source_reference` and `evidence` hold whatever the customer can point at: a
 * form URL, a ticket number, a filename. This platform verifies none of it and
 * claims to; the point is that the customer can show what they relied on.
 */
class ContactConsent extends Model
{
    protected $fillable = [
        'contact_id',
        'source_type',
        'source_reference',
        'method',
        'granted_at',
        'confirmed_at',
        'withdrawn_at',
        'evidence',
    ];

    protected function casts(): array
    {
        return [
            'source_type' => ConsentSource::class,
            'granted_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Whether this record is currently withheld.
     *
     * Revocation is a column rather than a deletion, so "the recipient withdrew"
     * stays distinguishable from "permission was never recorded". Deleting the
     * row would erase the fact that a decision was made and who made it.
     */
    public function isWithdrawn(): bool
    {
        return $this->withdrawn_at !== null;
    }
}

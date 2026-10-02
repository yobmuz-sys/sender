<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audience\ValidationMethod;
use App\Domain\Audience\ValidationReason;
use App\Domain\Audience\ValidationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One address found by one task, and what checking it established.
 *
 * Two kinds of information that must not be collapsed:
 *
 *   - `contact_id` is provenance. It says which canonical contact this row found,
 *     so the same person in ten extractions is one contact with ten edges rather
 *     than ten rows that cannot be related. The contact is never duplicated.
 *   - the `validation_*` columns are the snapshot *this task* produced. The
 *     contact holds the latest overall state, which is what eligibility reads;
 *     these hold what this run found, so a report is a record of a run and does
 *     not silently change next month because somebody rechecked the address.
 *
 * No `normalized_email` here. It is reachable through the contact, and copying
 * the key that defines contact identity onto a second table is an invitation to
 * the two disagreeing.
 */
class ExtractionResult extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'extraction_id',
        'email',
        'contact_id',
        'validation_status',
        'validation_reason',
        'validation_method',
        'validated_at',
    ];

    protected function casts(): array
    {
        return [
            'validation_status' => ValidationStatus::class,
            'validation_reason' => ValidationReason::class,
            'validation_method' => ValidationMethod::class,
            'validated_at' => 'datetime',
        ];
    }

    public function extraction(): BelongsTo
    {
        return $this->belongsTo(Extraction::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}

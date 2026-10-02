<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audience\SuppressionReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tenant-level instruction never to contact one address again.
 *
 * The property that makes this table matter is that a suppression outlives the
 * thing that created it. An unsubscribe, a hard bounce and a complaint are
 * recorded once, and from that moment apply to every list, every future
 * extraction, every re-import and every future campaign for that tenant — none
 * of which needs to know the suppression exists.
 *
 * That is the failure this table makes impossible:
 *
 *     recipient unsubscribes
 *         -> tenant imports the same list again tomorrow
 *             -> tenant sends to them again
 *
 * Re-importing is not a way to undo a recipient's decision. `unique(user_id,
 * contact_id)` is what makes that structural rather than merely intended: there
 * is one row per contact per tenant, so a second unsubscribe is a no-op instead
 * of a duplicate, and the recorded reason keeps the first cause unless something
 * stronger arrives.
 *
 * `created_at` without the rest of the timestamps because a suppression is
 * appended, never edited. There is no update path to an audit.
 */
class Suppression extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'contact_id',
        'reason',
        'source',
        'note',
        'created_at',
    ];

    protected $attributes = [
        'created_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'reason' => SuppressionReason::class,
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}

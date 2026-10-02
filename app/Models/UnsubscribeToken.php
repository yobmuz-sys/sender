<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A digest of one recipient's unsubscribe link.
 *
 * The token itself is shown once, to the code that puts it in a message, and is
 * not recoverable from here. Storing the digest rather than the token means a
 * database dump — which is exactly what a shared-hosting backup is — discloses
 * no working link and identifies nobody.
 *
 * Rows are not deleted when a link is used. The link keeps working after an
 * unsubscribe, because a recipient who clicks again — or clicks a link from a
 * message forwarded to them months later — must get the same answer, not an
 * error. Keeping the row is what makes that possible.
 */
class UnsubscribeToken extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'contact_id',
        'token_hash',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
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

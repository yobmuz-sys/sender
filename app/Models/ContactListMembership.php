<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One contact's membership of one list.
 *
 * A first-class model rather than an anonymous pivot for three reasons that all
 * matter at the size this stage operates at:
 *
 *   - `unique(list_id, contact_id)` makes duplicate membership impossible in the
 *     database. Adding a contact to a list it is already on is not an error the
 *     customer should see, and it must not become a second row.
 *   - the tenant is carried on the row and written with it, so the audience
 *     queries can scope by tenant without a second join on the largest table in
 *     the audience layer.
 *   - `added_at` makes "newly imported" answerable, which a bare pivot cannot.
 *
 * Holding no contact data of its own is deliberate. A membership that carried a
 * copy of the address would defeat the entire point of canonical contacts: one
 * row per person per tenant, with consent, suppression and validation recorded
 * once and applying everywhere.
 */
class ContactListMembership extends Model
{
    protected $table = 'list_contacts';

    public $timestamps = false;

    protected $fillable = [
        'list_id',
        'contact_id',
        'user_id',
        'added_at',
    ];

    protected function casts(): array
    {
        return [
            'added_at' => 'datetime',
        ];
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(ContactList::class, 'list_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}

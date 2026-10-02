<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audience\ValidationStatus;
use Database\Factories\ContactListFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named collection of a tenant's contacts.
 *
 * A contact belongs to many of these and each holds many contacts, so membership
 * lives in {@see ContactListMembership} and the contact is never copied. The
 * alternative — a contact row per list — would mean an unsubscribe applied to
 * one list had to be discovered and applied to its copies, and would make "this
 * person is on three lists" a question with no single answer.
 *
 * No lifecycle, no status column, no scheduled send. A list is a bucket with a
 * name and members; everything that would turn it into a campaign is a later
 * stage, and inventing the field now would imply a workflow the product does
 * not have.
 */
class ContactList extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'description',
    ];

    protected static function newFactory(): ContactListFactory
    {
        return ContactListFactory::new();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The membership rows themselves.
     *
     * The foreign key is named rather than inferred: the pivot calls it
     * `list_id`, and the convention would look for `contact_list_id`, which does
     * not exist and fails at query time rather than at boot.
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(ContactListMembership::class, 'list_id');
    }

    /**
     * The contacts on this list.
     *
     * A many-to-many rather than a hand-written join, so the membership row is
     * reachable and the query builder stays the framework's problem. The tenant
     * is carried on the pivot as well as derived through the list, because the
     * audience queries are scoped by tenant on every row they read and resolving
     * it through a second join on the largest table in the audience layer buys
     * nothing. See the pivot migration for the reasoning.
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(
            Contact::class,
            'list_contacts',
            'list_id',
            'contact_id',
        )->withPivot('added_at');
    }

    /**
     * How many contacts are on this list.
     *
     * A count rather than `count()` on the relation, because the list index
     * renders one number per list and loading every membership to produce it
     * would be the unbounded query this stage exists to remove. `withCount`
     * keeps it a single grouped aggregate.
     */
    public function scopeWithMemberCount($query)
    {
        return $query->withCount('memberships');
    }

    /**
     * How many of this list's members would actually be sendable today.
     *
     * Reported alongside the raw count because the two numbers diverge as soon
     * as any address is suppressed, lacks recorded consent, or could not be
     * validated — and a list page that showed only the raw count would look
     * ready to send when most of it is not.
     */
    public function readyCount(): int
    {
        return $this->memberships()
            ->join('contacts', 'contacts.id', '=', 'list_contacts.contact_id')
            ->where('contacts.user_id', $this->user_id)
            ->where('contacts.validation_status', ValidationStatus::LikelyActive->value)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('suppressions')
                    ->whereColumn('suppressions.contact_id', 'contacts.id')
                    ->where('suppressions.user_id', $this->user_id);
            })
            ->distinct()
            ->count('contacts.id');
    }
}

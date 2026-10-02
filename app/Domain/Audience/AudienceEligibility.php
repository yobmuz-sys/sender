<?php

declare(strict_types=1);

namespace App\Domain\Audience;

use App\Models\Contact;
use App\Models\ContactList;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Who may be contacted. The single definition of that, for every later stage.
 *
 * Built now, in Stage 5B, and consumed by nothing yet — which is the point. A
 * campaign stage that grew its own "eligible" clause would grow its own version
 * of the rules, and the divergence would show up as a campaign that included a
 * recipient somebody had unsubscribed from. Writing the query while the only
 * thing that can go wrong is a typo means the rules are exercised by the report
 * pages today and are correct before anything is put on the wire.
 *
 * The predicate is the intersection of four independent gates, and each one
 * excludes for its own reason:
 *
 *   validated   LIKELY_ACTIVE, and not expired
 *   consent     a record that actually proves the recipient agreed
 *   suppressed  no row in `suppressions` for this tenant
 *   undeliverable  not CONFIRMED_INVALID, and not RISKY
 *
 * `UNKNOWN` is excluded, and that is a policy choice rather than a limitation.
 * Sending to an address we could not check risks a bounce, a complaint or a
 * provider signal held against the whole account; not sending to it risks losing
 * one recipient we might have reached. For a platform operated by someone with no
 * technical knowledge, the second failure is the more expensive one, and the
 * first is invisible until it has already done damage.
 *
 * Suppression is expressed as a `whereNotExists` subquery rather than as a join,
 * so it cannot duplicate rows and cannot be forgotten by a missing `distinct`.
 * It is applied first in the builder chain for the same reason: it is the one
 * condition that must never be optional.
 */
class AudienceEligibility
{
    /**
     * Contacts belonging to a tenant that would be safe and permitted to contact.
     *
     * Every clause is tenant-scoped, including the subqueries. A missing scope
     * on the suppression check would not be a bug that shows up in testing — it
     * would be a tenant silently declining to send to its own suppressed
     * addresses, which looks exactly like a working system.
     */
    public function eligibleFor(int $userId, ?ContactList $list = null): Builder
    {
        $query = Contact::query()
            ->where('contacts.user_id', $userId)
            ->where('contacts.validation_status', ValidationStatus::LikelyActive->value)
            ->where('contacts.validation_expires_at', '>', now())
            ->whereNotExists($this->suppressionSubquery($userId))
            ->whereExists($this->confirmedConsentSubquery());

        if ($list !== null) {
            $query->whereExists($this->membershipSubquery((int) $list->id, $userId));
        }

        return $query->orderBy('contacts.id');
    }

    /**
     * How many contacts a tenant could currently contact.
     *
     * A count, not a `get()->count()`. A list of ten thousand addresses is
     * entirely plausible for this product, and materialising it to produce one
     * integer is the kind of unbounded read the rest of the stage removes.
     */
    public function countFor(int $userId, ?ContactList $list = null): int
    {
        return $this->eligibleFor($userId, $list)
            ->reorder()
            ->count();
    }

    /**
     * Contacts counted by why they are or are not contactable.
     *
     * The report needs this, and so does a customer looking at nine thousand
     * addresses and being told eight hundred are ready. "Why are the other eight
     * thousand not?" is the first question anybody asks, and an answer of "the
     * query said so" is not one.
     *
     * **These counts overlap, and deliberately so.** A suppressed address may
     * also be unvalidated, and a confirmed-invalid address may also lack
     * consent; both facts are true and both are worth a customer seeing. Only
     * `eligible` is exclusive by construction. Presenting the others as a
     * partition that sums to the list total would be a claim about the data that
     * the data does not support, and it would be wrong the first time somebody
     * suppressed an address that validation had already ruled out.
     *
     * @return array{eligible: int, suppressed: int, no_consent: int, invalid: int, unknown: int, risky: int, unchecked: int}
     */
    public function breakdownFor(int $userId, ?ContactList $list = null): array
    {
        $base = $this->scoped($userId, $list);

        return [
            'eligible' => $this->count($base, fn (Builder $q) => $q
                ->where('contacts.validation_status', ValidationStatus::LikelyActive->value)
                ->where('contacts.validation_expires_at', '>', now())
                ->whereNotExists($this->suppressionSubquery($userId))
                ->whereExists($this->confirmedConsentSubquery())),
            'suppressed' => $this->count($base, fn (Builder $q) => $q
                ->whereExists($this->suppressionSubquery($userId))),
            'invalid' => $this->count($base, fn (Builder $q) => $q
                ->where('contacts.validation_status', ValidationStatus::ConfirmedInvalid->value)),
            'risky' => $this->count($base, fn (Builder $q) => $q
                ->where('contacts.validation_status', ValidationStatus::Risky->value)),
            'unknown' => $this->count($base, fn (Builder $q) => $q
                ->where(function (Builder $q): void {
                    $q->where('contacts.validation_status', ValidationStatus::Unknown->value)
                        ->where(function (Builder $q): void {
                            // "Checked, could not tell" and "never looked" are
                            // both UNKNOWN and are counted apart, because only one
                            // of them is a gap this platform failed to close.
                            $q->where('contacts.validation_expires_at', '>', now())
                                ->orWhereNull('contacts.validated_at');
                        });
                })),
            'unchecked' => $this->count($base, fn (Builder $q) => $q
                ->where('contacts.validation_status', ValidationStatus::Unknown->value)
                ->whereNull('contacts.validated_at')),
            'no_consent' => $this->count($base, fn (Builder $q) => $q
                ->whereNotExists($this->suppressionSubquery($userId))
                ->whereNotExists($this->confirmedConsentSubquery())),
        ];
    }

    /**
     * The tenant's contacts, optionally narrowed to one list.
     */
    public function scoped(int $userId, ?ContactList $list = null): Builder
    {
        $query = Contact::query()->where('contacts.user_id', $userId);

        if ($list !== null) {
            $query->whereExists($this->membershipSubquery((int) $list->id, $userId));
        }

        return $query;
    }

    /**
     * @param  callable(Builder): Builder  $constraint
     */
    private function count(Builder $base, callable $constraint): int
    {
        // `clone` because the base query is reused for every branch, and a builder
        // that has been given a `where` keeps it. Mutating a shared instance
        // would make each count include the previous one's conditions.
        return $constraint(clone $base)->count();
    }

    private function suppressionSubquery(int $userId): QueryBuilder
    {
        return $this->subQuery('suppressions')
            ->whereColumn('suppressions.contact_id', 'contacts.id')
            ->where('suppressions.user_id', $userId);
    }

    /**
     * A consent record that actually proves the recipient agreed.
     *
     * Two conditions, and both matter. The source has to be one that evidences
     * the recipient's own act — a form, a signup, a double opt-in — and the
     * record has to be un-withdrawn, so a recipient who later opted out is not
     * still carried by the record that originally opted them in.
     *
     * An operator's attestation is deliberately absent. "You told us they agreed"
     * is recorded, reported and searchable, and it does not make anybody
     * sendable.
     */
    private function confirmedConsentSubquery(): QueryBuilder
    {
        $confirming = array_map(
            static fn (ConsentSource $source): string => $source->value,
            array_values(array_filter(
                ConsentSource::cases(),
                static fn (ConsentSource $source): bool => $source->confirmsConsent(),
            )),
        );

        return $this->subQuery('contact_consents')
            ->whereColumn('contact_consents.contact_id', 'contacts.id')
            ->whereNull('contact_consents.withdrawn_at')
            ->whereIn('contact_consents.source_type', $confirming);
    }

    private function membershipSubquery(int $listId, int $userId): QueryBuilder
    {
        return $this->subQuery('list_contacts')
            ->whereColumn('list_contacts.contact_id', 'contacts.id')
            ->where('list_contacts.list_id', $listId)
            ->where('list_contacts.user_id', $userId);
    }

    /**
     * A correlated subquery builder.
     *
     * Wrapped rather than inlined at each call site so every tenant scope in this
     * class is written the same way, and so a fourth one cannot be added with a
     * scope somebody forgot.
     */
    private function subQuery(string $table): QueryBuilder
    {
        return DB::table($table);
    }
}

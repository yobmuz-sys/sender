<?php

declare(strict_types=1);

namespace App\Domain\Audience;

use App\Models\Contact;
use App\Models\ContactConsent;
use DateTimeInterface;

/**
 * The consent a contact currently holds, derived from the records kept about it.
 *
 * Three states, and the middle one is the point. A `consented` boolean cannot
 * distinguish "the recipient signed up" from "somebody pasted this list" from
 * "somebody typed this in by hand", and those three call for entirely different
 * decisions. So consent is stored as evidence and *derived*, never as a flag.
 *
 * `Unknown` is the default and by far the most common case, because a scraped or
 * purchased list has no consent attached to it. That is not a defect in the
 * data — it is the truth about it — and a platform that quietly promoted it to
 * `Confirmed` would be manufacturing permission from nothing.
 *
 * The derivation is deliberately conservative, and the order matters:
 *
 *   1. a withdrawal anywhere in the history wins over everything
 *   2. otherwise, a record from a source that *proves* the recipient agreed wins
 *   3. otherwise `Unknown`
 *
 * Step 1 first because a recipient who asked to stop must be able to revoke
 * without an operator having to find and edit the record that says they opted in.
 * Step 2 requires `confirmsConsent()`, which is false for an operator's own
 * attestation: "somebody told me this person opted in" is not the same as holding
 * a record that they did, and the difference is exactly what this table exists to
 * keep.
 */
class ConsentLedger
{
    /**
     * The status a contact currently holds.
     *
     * @param  list<ContactConsent>  $consents
     */
    public function statusFor(array $consents): ConsentStatus
    {
        if ($consents === []) {
            return ConsentStatus::Unknown;
        }

        // A withdrawal outranks everything, including a later confirmation. The
        // only thing that lifts it is a newer record, and comparing timestamps
        // rather than array order keeps that independent of insertion order.
        $latestWithdrawal = $this->latest($consents, static fn (ContactConsent $c): bool => $c->isWithdrawn());
        $latestConfirmation = $this->latest(
            $consents,
            static fn (ContactConsent $c): bool => ! $c->isWithdrawn() && $c->source_type->confirmsConsent(),
        );

        if ($latestWithdrawal === null) {
            return $latestConfirmation === null ? ConsentStatus::Unknown : ConsentStatus::Confirmed;
        }

        if ($latestConfirmation === null) {
            return ConsentStatus::Withdrawn;
        }

        return $latestWithdrawal !== null && $latestWithdrawal->getTimestamp() >= $latestConfirmation->getTimestamp()
            ? ConsentStatus::Withdrawn
            : ConsentStatus::Confirmed;
    }

    /**
     * The status of a contact as stored, without loading its consent history.
     *
     * Written as a single aggregate rather than N queries or a per-contact
     * lookup, because the audience and list pages read this for every row they
     * render. Returning `Unknown` for a contact whose consents were not loaded
     * is the honest answer: with no evidence in hand, there is no evidence.
     */
    public function statusOf(Contact $contact): ConsentStatus
    {
        return $contact->relationLoaded('consents')
            ? $this->statusFor($contact->consents->all())
            : ConsentStatus::Unknown;
    }

    /**
     * Record evidence that a recipient agreed to be contacted.
     */
    public function record(
        Contact $contact,
        ConsentSource $source,
        ?string $reference = null,
        ?DateTimeInterface $grantedAt = null,
        bool $recipientConfirmed = false,
        ?string $evidence = null,
    ): ContactConsent {
        return $contact->consents()->create([
            'source_type' => $source->value,
            'source_reference' => $reference,
            'method' => $recipientConfirmed ? 'recipient_confirmed' : 'declared',
            'granted_at' => $grantedAt ?? now(),
            // Set only where the record itself proves control of the mailbox.
            // An operator attestation leaves this null, which is precisely what
            // keeps the derived status at `Unknown`.
            'confirmed_at' => $recipientConfirmed ? ($grantedAt ?? now()) : null,
            'evidence' => $evidence,
        ]);
    }

    /**
     * Record a withdrawal against every current record for a contact.
     *
     * Marking rather than deleting. The record that permission once existed is
     * part of the history, and "withdrawn" has to stay distinguishable from
     * "never had any".
     */
    public function withdraw(Contact $contact, ?DateTimeInterface $at = null): int
    {
        return $contact->consents()
            ->whereNull('withdrawn_at')
            ->update(['withdrawn_at' => $at ?? now(), 'updated_at' => now()]);
    }

    /**
     * The most recent moment at which any matching record took effect.
     *
     * Which timestamp counts depends on what is being compared: a grant is dated
     * by when permission was given, a withdrawal by when it was revoked. Using
     * the grant date for a withdrawal would place it in the past and let an
     * older opt-in silently reinstate a recipient who has since asked to stop.
     *
     * @param  list<ContactConsent>  $consents
     * @param  callable(ContactConsent): bool  $matches
     */
    private function latest(array $consents, callable $matches): ?DateTimeInterface
    {
        $latest = null;

        foreach ($consents as $consent) {
            if (! $matches($consent)) {
                continue;
            }

            $at = $consent->isWithdrawn()
                ? $consent->withdrawn_at
                : ($consent->confirmed_at ?? $consent->granted_at ?? $consent->created_at);

            if ($at === null) {
                continue;
            }

            if ($latest === null || $at->getTimestamp() >= $latest->getTimestamp()) {
                $latest = $at;
            }
        }

        return $latest;
    }
}

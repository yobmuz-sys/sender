<?php

declare(strict_types=1);

namespace App\Domain\Audience;

use App\Models\Contact;

/**
 * Mailbox-level validation evidence, held on the canonical contact.
 *
 * Kept apart from {@see DomainValidationCache} on purpose, because the two
 * answers have very different lifetimes:
 *
 *   - a domain's mail route changes on the scale of days
 *   - a mailbox's answer to "will you take mail for this address" can change
 *     between one message and the next, and can change without any DNS change at
 *     all
 *
 * So a mailbox result is *always* stored with an expiry and is *never* treated as
 * permanent. There is no "confirmed valid forever" state, and a mailbox is never
 * marked permanently active, because a platform that stored such a mark would be
 * asserting something about a mailbox it has no way to re-check — and that
 * assertion is what turns a healthy list into a burst of bounces the first time a
 * provider's policy changes.
 *
 * Confirmed-invalid results are cached for longer, and the asymmetry is
 * deliberate: a mailbox that does not exist is a stable fact, and re-probing it
 * costs the receiving server work in exchange for no new information.
 *
 * Every method is scoped by tenant. A cache keyed on the address alone would let
 * one account inherit another's evidence about the same recipient.
 */
class MailboxValidationCache
{
    /**
     * The current result for an address belonging to one tenant, or null.
     */
    public function fresh(int $userId, string $normalizedEmail): ?ValidationOutcome
    {
        $contact = Contact::query()
            ->where('user_id', $userId)
            ->where('normalized_email', $normalizedEmail)
            ->first();

        if ($contact === null || $contact->validated_at === null || $contact->validation_expires_at === null) {
            return null;
        }

        return $contact->validation_expires_at->isPast() ? null : $this->outcomeFor($contact);
    }

    /**
     * Store a result against the tenant's canonical contact.
     *
     * A separate entry point from {@see applyTo()} because the pipeline runs
     * before the job has a contact in hand — the contact may not exist yet, since
     * finding or creating it is the extraction step's work.
     */
    public function put(int $userId, string $normalizedEmail, ValidationOutcome $outcome): void
    {
        Contact::query()
            ->where('user_id', $userId)
            ->where('normalized_email', $normalizedEmail)
            ->each(function (Contact $contact) use ($outcome): void {
                $this->applyTo($contact, $outcome);
            });
    }

    /**
     * Record a result against a contact the caller already holds.
     */
    public function applyTo(Contact $contact, ValidationOutcome $outcome): void
    {
        $contact->forceFill([
            'validation_status' => $outcome->status->value,
            'validation_reason' => $outcome->reason->value,
            'validation_method' => $outcome->method->value,
            'last_smtp_code' => $outcome->smtpCode,
            'last_enhanced_code' => $outcome->enhancedCode,
            'validated_at' => $outcome->checkedAt ?? now(),
            'validation_expires_at' => now()->addSeconds($outcome->status->cacheForSeconds(
                (int) config('sender.validation.mailbox_cache_ttl_seconds', 604800),
                (int) config('sender.validation.invalid_cache_ttl_seconds', 2592000),
            )),
        ])->save();
    }

    /**
     * The outcome a contact currently records.
     *
     * Reads stored columns rather than a serialised outcome so the row stays
     * queryable: the audience query has to select `LIKELY_ACTIVE` contacts from
     * the database, not from an array of deserialised objects.
     *
     * Reconstruction goes through the {@see ValidationOutcome} factory methods so
     * that type's invariants still hold for a value read back out of the
     * database. A row claiming a definitive reason without the evidence for it
     * cannot become a confirmed-invalid outcome here — see
     * {@see rebuildInvalid()}.
     */
    public function outcomeFor(Contact $contact): ValidationOutcome
    {
        $status = $this->enumOrNull(ValidationStatus::class, $contact->validation_status);

        if ($status === null) {
            return ValidationOutcome::notValidated();
        }

        $reason = $this->enumOrNull(ValidationReason::class, $contact->validation_reason)
            ?? ValidationReason::NotValidated;

        return match ($status) {
            ValidationStatus::ConfirmedInvalid => $this->rebuildInvalid($contact, $reason),
            ValidationStatus::LikelyActive => ValidationOutcome::likelyActive($contact->last_smtp_code),
            ValidationStatus::Risky => ValidationOutcome::risky($reason),
            ValidationStatus::Unknown => ValidationOutcome::unknown(
                $reason,
                $contact->last_smtp_code,
                $contact->last_enhanced_code,
            ),
        };
    }

    /**
     * Read a column that the model already casts, or one that is not cast.
     *
     * The model casts these columns to their enums, so the value in hand is
     * usually the enum itself and casting it to string first is a type error
     * rather than a no-op. A row written by a migration or a query builder comes
     * back uncast, so both shapes are accepted here — and neither is trusted to
     * be in the vocabulary, since a hand-edited row is not the same thing as a
     * checked one.
     *
     * @template T of object
     *
     * @param  class-string<T>  $enum
     * @return T|null
     */
    private function enumOrNull(string $enum, mixed $value): ?object
    {
        if ($value === null || $value instanceof $enum) {
            return $value;
        }

        return $enum::tryFrom((string) $value);
    }

    /**
     * A confirmed-invalid result rebuilt from a stored row.
     *
     * The reason decides the shape, because each definitive reason has exactly one
     * legitimate origin. A row claiming {@see ValidationReason::MailboxNotFound}
     * without an enhanced status code is not something this platform ever writes,
     * and reading one back as a confirmed-invalid outcome would let a corrupted or
     * hand-edited row manufacture exactly the verdict this stage exists to
     * protect.
     */
    private function rebuildInvalid(Contact $contact, ValidationReason $reason): ValidationOutcome
    {
        $enhanced = $contact->last_enhanced_code === null ? '' : (string) $contact->last_enhanced_code;

        return match ($reason) {
            ValidationReason::InvalidSyntax => ValidationOutcome::invalidSyntax(),
            ValidationReason::DomainNotFound => ValidationOutcome::domainNotFound(),
            ValidationReason::NoMailRoute => ValidationOutcome::noMailRoute(),
            ValidationReason::MailboxNotFound => $enhanced !== ''
                ? ValidationOutcome::mailboxNotFound($enhanced, $contact->last_smtp_code)
                // The evidence a confirmed-invalid verdict requires is missing,
                // so the address reads as unknown rather than as proven dead.
                : ValidationOutcome::unknown(ValidationReason::NotValidated),
            default => ValidationOutcome::unknown(ValidationReason::NotValidated),
        };
    }
}

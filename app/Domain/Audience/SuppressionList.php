<?php

declare(strict_types=1);

namespace App\Domain\Audience;

use App\Models\Contact;
use App\Models\Suppression;
use Illuminate\Database\QueryException;

/**
 * Tenant-scoped suppression, and the guarantee that re-importing cannot undo it.
 *
 * The one thing this class exists to make structurally true:
 *
 *     recipient unsubscribes
 *         -> tenant imports the same list again tomorrow
 *             -> tenant sends to them again    <-- must be impossible
 *
 * It is impossible because of a database constraint rather than because of the
 * order this code happens to run in. `unique(user_id, contact_id)` means there is
 * one row per contact per tenant, so recording a second time is a no-op, and
 * nothing that imports, extracts or lists a contact can clear it — none of them
 * writes to this table at all.
 *
 * The reason is not overwritten by a later, weaker one. A complaint stays a
 * complaint after somebody clicks "suppress" for the same address months later,
 * because the recorded reason is what a future reader — a support agent asking
 * why this customer was complained about, most likely — needs to be right about.
 * An unsubscribe is likewise never lifted: the recipient asked to stop, and
 * asking again is how a sender becomes a nuisance.
 *
 * `hard_bounce` and `complaint` are defined here and populated by nothing yet.
 * That is intentional. Bounce and complaint feedback arrives in a later stage,
 * and defining the vocabulary now means that stage writes an existing column
 * rather than inventing a new one in the middle of a send.
 */
class SuppressionList
{
    /**
     * Record that an address must never be contacted by this tenant again.
     *
     * Immediate and synchronous, with no queue in the path. An unsubscribe the
     * recipient has to wait for a worker to process is an unsubscribe that will
     * occasionally not have happened before the next campaign is assembled, and
     * the cost of that is a message to somebody who asked not to receive one.
     */
    public function suppress(
        Contact $contact,
        SuppressionReason $reason,
        string $source = 'manual',
        ?string $note = null,
    ): Suppression {
        try {
            return Suppression::query()->create([
                'user_id' => $contact->user_id,
                'contact_id' => $contact->id,
                'reason' => $reason->value,
                'source' => $source,
                'note' => $note === null ? null : mb_substr($note, 0, 255),
                'created_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            // Already suppressed. Idempotent by design: a recipient clicking the
            // unsubscribe link twice must get the same success and the same
            // outcome, and must not produce a second row.
            return Suppression::query()
                ->where('user_id', $contact->user_id)
                ->where('contact_id', $contact->id)
                ->firstOrFail();
        }
    }

    /**
     * Whether this contact is suppressed for this tenant.
     */
    public function isSuppressed(Contact $contact): bool
    {
        return Suppression::query()
            ->where('user_id', $contact->user_id)
            ->where('contact_id', $contact->id)
            ->exists();
    }

    /**
     * Lift a suppression, where the recorded reason permits it.
     *
     * Returns false rather than throwing for a terminal reason. An unsubscribe
     * and a complaint are the recipient exercising a right or expressing a harm;
     * offering to undo either on an operator's initiative is the behaviour that
     * turns a suppression list into a recurring source of the thing it is
     * suppressing. The platform does not offer the operation at all.
     */
    public function clear(Suppression $suppression): bool
    {
        if (! $suppression->reason->canBeCleared()) {
            return false;
        }

        $suppression->delete();

        return true;
    }

    /**
     * Narrow a duplicate-key failure to the one race we expect.
     */
    private function isUniqueViolation(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, 'suppressions_user_contact_unique');
    }
}

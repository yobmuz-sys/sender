<?php

declare(strict_types=1);

namespace App\Domain\Audience;

use App\Models\Contact;
use App\Models\Extraction;
use App\Models\ExtractionResult;
use Illuminate\Database\QueryException;

/**
 * Turns extraction results into canonical contacts.
 *
 * Before this, an address existed only as a row belonging to one extraction. The
 * same person found on ten pages was ten rows with nothing to say they were the
 * same person — and therefore nowhere to record a consent, a suppression or a
 * validation once and have it apply everywhere. The whole audience layer rests
 * on collapsing those rows, and this is the class that does it.
 *
 * Three properties matter, and each costs something to get right:
 *
 *   - **Batched.** A paste of ten thousand addresses is walked in fixed-size
 *     passes with the key set held per batch, never for the whole extraction.
 *     Holding a ten-thousand-element map is survivable; holding one while also
 *     running DNS and an SMTP conversation per address is not, and the worker's
 *     memory ceiling is not negotiable.
 *   - **Idempotent by constraint, not by check.** The insert is an upsert against
 *     `unique(user_id, normalized_email)`, and the link is an upsert against
 *     `extraction_id + email`. Nothing asks "does this exist" first, because that
 *     check-then-write leaves a window a concurrent worker walks straight through.
 *   - **Re-running never resets evidence.** A contact that was already validated
 *     keeps its state. Re-importing a list must not make a previously checked
 *     address unknown again, and it certainly must not clear a suppression — the
 *     upsert only ever writes the identity columns.
 */
class ContactSyncer
{
    /**
     * How many results are resolved to contacts per pass.
     *
     * Sized against the deployment's job batch limit rather than to its own
     * preference: two sources of truth for one ceiling is how a ceiling stops
     * meaning anything.
     */
    public function __construct(
        private readonly int $batchSize,
    ) {}

    public static function fromConfiguration(): self
    {
        return new self((int) config('sender.extraction.batch_size', 250));
    }

    /**
     * Resolve unlinked results of one extraction to canonical contacts, within a
     * ceiling.
     *
     * The ceiling is what makes this usable from inside a bounded job. A paste of
     * ten thousand addresses is walked in batches either way, but an unbounded
     * call would still run for the whole list in one worker invocation and be
     * killed part-way through. With a ceiling, a pass finishes inside its budget
     * and the next pass picks up where it stopped.
     *
     * @param  int|null  $limit  Maximum results to link this pass; null walks the
     *                           whole extraction.
     * @return int The number of results linked by this pass.
     */
    public function sync(Extraction $extraction, ?int $limit = null): int
    {
        $query = $extraction->results()
            // Only rows with no contact yet. On a retry, or on a re-run of a task
            // that was interrupted, this is the resume point rather than the
            // start of the whole job again.
            ->whereNull('contact_id')
            ->orderBy('id');

        if ($limit !== null) {
            $query->limit(max(1, $limit));
        }

        // Sliced in PHP rather than with `chunkById`, because `chunkById`
        // re-applies its own limit per page and would discard the ceiling above —
        // turning a bounded pass back into an unbounded one. Holding one pass's
        // rows is the point: the ceiling *is* the memory bound.
        $linked = 0;

        foreach (array_chunk($query->get()->all(), $this->batchSize) as $batch) {
            $linked += $this->linkBatch($extraction, $batch);
        }

        return $linked;
    }

    /**
     * The tenant's canonical contact for an address, creating it if needed.
     *
     * Written as an insert-then-recover rather than an upsert so the returned row
     * is always a real model. The catch is narrow and specific: it fires only on
     * the unique violation, which is the one outcome that means "another worker
     * got there first" — and the re-read then finds that worker's row, which is
     * the correct row.
     */
    public function contactFor(int $userId, string $email): Contact
    {
        $key = EmailAddress::key($email);

        try {
            return Contact::query()->create([
                'user_id' => $userId,
                'email' => EmailAddress::display($email),
                'normalized_email' => $key,
            ]);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $existing = Contact::query()
                ->where('user_id', $userId)
                ->where('normalized_email', $key)
                ->first();

            if ($existing === null) {
                // The constraint fired for some other reason — a foreign key, a
                // not-null column. Re-throwing is right: swallowing it would
                // report a successful sync for rows that were never linked.
                throw $exception;
            }

            return $existing;
        }
    }

    /**
     * @param  list<ExtractionResult>  $results
     */
    private function linkBatch(Extraction $extraction, array $results): int
    {
        $userId = (int) $extraction->user_id;

        $contacts = [];
        $keys = [];

        // One contact resolution per distinct key in the batch. Ten results at
        // the same company domain are usually ten different local parts, but a
        // pasted list with a header row or a duplicated block is common enough
        // that resolving each key once is worth the map.
        foreach ($results as $result) {
            $keys[EmailAddress::key((string) $result->email)] = true;
        }

        // `insertOrIgnore` rather than an upsert, because only the identity
        // columns are written. An upsert's update list would have to name them
        // individually and would grow every time a contact column was added; the
        // identity is immutable and the evidence is somebody else's to write.
        $now = now();

        $rows = [];

        foreach (array_keys($keys) as $key) {
            $rows[] = [
                'user_id' => $userId,
                'email' => $key,
                'normalized_email' => $key,
                'validation_status' => ValidationStatus::Unknown->value,
                'validation_reason' => ValidationReason::NotValidated->value,
                'validation_method' => ValidationMethod::None->value,
                'is_catch_all' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        Contact::query()->insertOrIgnore($rows);

        $contacts = Contact::query()
            ->where('user_id', $userId)
            ->whereIn('normalized_email', array_keys($keys))
            ->get()
            ->keyBy('normalized_email');

        $links = [];

        foreach ($results as $result) {
            $contact = $contacts->get(EmailAddress::key((string) $result->email));

            if ($contact === null) {
                // No contact row for this key, which means the insert above was
                // ignored for a reason other than duplication. Skipping is
                // correct — a later pass will retry it — and leaving the result
                // with a null contact is honest about that.
                continue;
            }

            // `extraction_id` and `email` ride along even though only the link is
            // being changed: an upsert is an INSERT first, and both are NOT NULL
            // with no default. Carrying the columns the row already has makes the
            // statement idempotent without a read-modify-write.
            $links[] = [
                'id' => $result->id,
                'extraction_id' => $result->extraction_id,
                'email' => $result->email,
                'contact_id' => $contact->id,
            ];
        }

        if ($links === []) {
            return 0;
        }

        // Keyed on the result's own id, so a re-run converges rather than
        // duplicating. The result row is the thing being updated, not created.
        ExtractionResult::query()->upsert($links, ['id'], ['contact_id']);

        return count($links);
    }

    /**
     * Whether a database failure was the unique-constraint race we expect.
     *
     * Checked rather than assumed, because treating *any* failure as "somebody
     * else got there first" would silently return a contact that does not exist
     * and write a link to nothing.
     */
    private function isUniqueViolation(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, 'contacts_user_email_unique');
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Audience\ContactSyncer;
use App\Domain\Audience\MailboxValidationCache;
use App\Domain\Audience\ValidationMethod;
use App\Domain\Audience\ValidationPipeline;
use App\Domain\Audience\ValidationReason;
use App\Domain\Audience\ValidationStatus;
use App\Domain\Extraction\ExtractionStatus;
use App\Domain\Extraction\PendingTaskQueue;
use App\Domain\System\Queue\WorkerBounds;
use App\Models\Extraction;
use App\Models\ExtractionResult;
use App\Models\Suppression;
use App\Support\SensitiveData;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Checks the addresses one extraction found.
 *
 * A separate job from {@see ProcessExtractionJob}, and the separation is the
 * central design decision of this stage. Validating inside the extraction job
 * would produce one job that fetches a page, matches ten thousand addresses,
 * performs up to thirty thousand DNS and SMTP operations and writes thirty
 * thousand rows — inside a worker whose entire runtime budget is 240 seconds. It
 * would overrun, be killed and retried, and the customer would watch a badge
 * that never moves. Split, each job does one bounded thing and each can be
 * killed and resumed without losing the other's work.
 *
 * **Bounded passes, re-queuing rather than looping.**
 *
 * A pass links at most `max_per_pass` results to canonical contacts and then
 * checks at most that many, committing in batches of `batch_size`. Past its
 * budget it dispatches itself and returns, so a list of any size is a series of
 * passes rather than one that overruns. A job that looped until the list was
 * finished would hold the worker open for the whole run and break the queue
 * reservation invariant the platform's worker bounds exist to protect.
 *
 * **Network I/O never happens inside a database transaction.** Each result is
 * written immediately after its check returns, in its own write, so nothing is
 * left holding a row lock while the worker waits on a third party's timeout.
 * Counters are then recomputed from the results table in a grouped query, which
 * means an interrupted pass leaves figures that are true of the work completed
 * rather than a single total written at the end and lost to a kill.
 *
 * **Retry-safe, structurally rather than by convention.** Contacts are keyed by
 * `unique(user_id, normalized_email)` and results by `unique(extraction_id,
 * email)`, so a repeated pass converges instead of duplicating. The counters are
 * recomputed rather than incremented, so a batch that somehow committed twice is
 * counted once. Nothing here depends on the job running exactly once.
 */
class ValidateExtractionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Finite execution, held under the connection's `retry_after` for the same
     * reason {@see ProcessExtractionJob} does: a worker that overruns must be
     * killed and retried rather than have its job handed to a second worker while
     * it still holds it.
     */
    public int $timeout;

    public int $tries;

    /**
     * Results resolved and results checked per pass.
     *
     * The whole pass is held in memory rather than streamed, so this number is
     * also a memory ceiling. 150 rows is a few tens of kilobytes, and it is the
     * reason a list of any size costs the same per worker invocation.
     */
    private readonly int $maxPerPass;

    public function __construct(public int $extractionId)
    {
        $bounds = WorkerBounds::resolve();

        $this->timeout = $bounds->jobTimeoutSeconds();
        $this->tries = $bounds->maxAttempts;

        $this->maxPerPass = max(1, (int) config('sender.validation.max_per_pass', 150));
    }

    public function handle(
        ValidationPipeline $pipeline,
        MailboxValidationCache $mailboxCache,
        ContactSyncer $syncer,
        PendingTaskQueue $queue,
    ): void {
        $extraction = Extraction::query()->find($this->extractionId);

        if ($extraction === null) {
            throw new ModelNotFoundException('extraction '.$this->extractionId.' no longer exists');
        }

        // A task that has been cancelled, or has already finished, is not
        // re-entered. Without this a re-dispatched pass would keep a cancelled
        // task's badge ticking upward, which is the one thing a cancellation must
        // never do.
        if (in_array($extraction->status, [ExtractionStatus::Cancelled, ExtractionStatus::Ready], true)) {
            return;
        }

        $extraction->forceFill([
            'status' => ExtractionStatus::Validating->value,
            'validation_started_at' => $extraction->validation_started_at ?? now(),
        ])->save();

        // Linking comes first, in the same bounded budget. A result with no
        // canonical contact cannot be validated and cannot be reported on, so a
        // task that skipped this step would produce a report of zeroes against a
        // list of ten thousand addresses and look as though the addresses were
        // all dead.
        $linked = $syncer->sync($extraction, $this->maxPerPass);

        $checked = 0;

        $pending = $extraction->results()
            ->whereNull('validation_status')
            ->with('contact')
            ->orderBy('id')
            ->limit($this->maxPerPass)
            ->get();

        // One query for the whole pass rather than one per address. This is not
        // only about speed: a suppression check that ran after the socket was
        // already open would be no check at all.
        $suppressed = $this->suppressedContactIds($extraction, $pending);

        $pending->each(function (ExtractionResult $result) use ($extraction, $pipeline, $mailboxCache, $syncer, $suppressed, &$checked): void {
            if ($this->checkResult($extraction, $result, $pipeline, $mailboxCache, $syncer, $suppressed)) {
                $checked++;
            }
        });

        // Recomputed, not carried forward. Every pass derives its counters from
        // what is actually in the results table, which is what makes a retry
        // converge rather than compound.
        $this->recount($extraction);

        if ($checked > 0 && $this->remainingUnchecked($extraction) > 0) {
            // More to do and this pass made progress, so it was bounded rather
            // than wedged.
            self::dispatch($this->extractionId);

            return;
        }

        if ($checked === 0 && $linked > 0) {
            // The results were linked but nothing could be classified. A second
            // pass would link nothing new and classify nothing new, so asking
            // again would be a worker invocation that accomplishes nothing. The
            // badge ends up reading "found N, checked none", which is exactly
            // what happened and is far better than an endless re-dispatch.
            $extraction->forceFill([
                'status' => ExtractionStatus::Ready->value,
                'validation_completed_at' => now(),
            ])->save();

            $queue->dispatchNext((int) $extraction->user_id);

            return;
        }

        $extraction->forceFill([
            'status' => ExtractionStatus::Ready->value,
            'validation_completed_at' => now(),
            // The task is finished only when the whole pipeline is. `started_at`
            // and `completed_at` bracket all of it, not just the extraction, so
            // the two columns still mean what they say on the badge.
            'completed_at' => $extraction->completed_at ?? now(),
        ])->save();

        // Only now does the account's next task start. The pipeline is a
        // sequence, and beginning a second extraction while this one is still
        // being checked would put two workers' worth of network work on one
        // shared host for no benefit to either customer.
        $queue->dispatchNext((int) $extraction->user_id);
    }

    /**
     * Check one address and write both of its records.
     *
     * The result row carries the snapshot the report reads; the contact carries
     * the latest state the eligibility query reads. Writing both, in that order,
     * means a report and an eligibility check cannot disagree about the same
     * address at the same moment.
     *
     * @return bool Whether a classification was actually recorded.
     */
    private function checkResult(
        Extraction $extraction,
        ExtractionResult $result,
        ValidationPipeline $pipeline,
        MailboxValidationCache $mailboxCache,
        ContactSyncer $syncer,
        array $suppressed = [],
    ): bool {
        $contact = $result->contact ?? $this->ensureContact($extraction, $result, $syncer);

        if ($contact === null) {
            // No contact could be established, so there is nothing to check and
            // nothing truthful to record. Inventing a classification here would
            // manufacture a verdict from no evidence, which is the exact failure
            // the whole pipeline is built to avoid. The row is revisited on the
            // next pass, because it is still unclassified.
            return false;
        }

        if (isset($suppressed[(int) $contact->id])) {
            // Already suppressed, so this address will not be contacted whatever
            // any mail server says. Opening a socket to a recipient who asked to
            // stop is both pointless and the kind of repeated contact with a
            // provider's infrastructure that gets a sending host blocked.
            //
            // The row is still recorded, as "not checked". Leaving it unclassified
            // would be dishonest in the other direction — the report would read
            // "9,742 of 10,000 checked" and leave the customer to work out which
            // 258, when the reason is already recorded on the contact and shown
            // on the list page.
            $result->forceFill([
                'validation_status' => ValidationStatus::Unknown->value,
                'validation_reason' => ValidationReason::NotValidated->value,
                'validation_method' => ValidationMethod::None->value,
                'validated_at' => null,
            ])->save();

            return true;
        }

        $verdict = $pipeline->validate((string) $contact->email, (int) $contact->user_id);

        $outcome = $verdict->outcome;

        // The outcome's own method records whether it was freshly observed or
        // reused from the mailbox cache, and the report shows it under "Why?".
        // Presenting a month-old acceptance as a fresh one is a claim this
        // platform cannot support, and the method is how it is prevented.
        $mailboxCache->applyTo($contact, $outcome);

        $result->forceFill([
            'validation_status' => $outcome->status->value,
            'validation_reason' => $outcome->reason->value,
            'validation_method' => $outcome->method->value,
            'validated_at' => $outcome->checkedAt ?? now(),
        ])->save();

        return true;
    }

    /**
     * Establish the canonical contact for a result the sync pass did not reach.
     *
     * The normal path is the batched sync above. This is the recovery path for a
     * result whose insert was ignored for some reason other than duplication —
     * and it is here rather than left to null because a silently unlinked result
     * is a report that quietly under-counts, which is the failure a customer
     * cannot detect and therefore cannot trust.
     */
    private function ensureContact(Extraction $extraction, ExtractionResult $result, ContactSyncer $syncer)
    {
        $contact = $syncer->contactFor((int) $extraction->user_id, (string) $result->email);

        $result->forceFill(['contact_id' => $contact->id])->save();

        return $contact;
    }

    /**
     * The contacts in this pass that are already suppressed for their tenant.
     *
     * Keyed by contact id so the check is an array lookup, and scoped by tenant in
     * the query rather than by trusting the rows the caller already holds: a
     * suppression belonging to a different account is not a suppression at all.
     *
     * @param  Collection<int, ExtractionResult>  $results
     * @return array<int, true>
     */
    private function suppressedContactIds(Extraction $extraction, $results): array
    {
        $contactIds = $results
            ->pluck('contact_id')
            ->filter(static fn ($id): bool => $id !== null)
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($contactIds === []) {
            return [];
        }

        return Suppression::query()
            ->where('user_id', $extraction->user_id)
            ->whereIn('contact_id', $contactIds)
            ->pluck('contact_id')
            ->mapWithKeys(static fn ($id): array => [(int) $id => true])
            ->all();
    }

    /**
     * Recompute the task's classification counters from its results.
     *
     * Four grouped counts over the indexed `(extraction_id, validation_status)`
     * pair — bounded by the number of results, not by how far the run has got, so
     * a re-run costs the same as the first.
     */
    private function recount(Extraction $extraction): void
    {
        $counts = [];

        foreach (ValidationStatus::all() as $status) {
            $counts[$status->value] = 0;
        }

        $rows = $extraction->results()
            ->whereNotNull('validation_status')
            ->selectRaw('validation_status, count(*) as aggregate')
            ->groupBy('validation_status')
            ->pluck('aggregate', 'validation_status');

        foreach ($rows as $status => $aggregate) {
            if (array_key_exists((string) $status, $counts)) {
                $counts[(string) $status] = (int) $aggregate;
            }
        }

        $extraction->forceFill([
            'validation_processed_count' => (int) $rows->sum(),
            'likely_active_count' => $counts[ValidationStatus::LikelyActive->value],
            'confirmed_invalid_count' => $counts[ValidationStatus::ConfirmedInvalid->value],
            'unknown_count' => $counts[ValidationStatus::Unknown->value],
            'risky_count' => $counts[ValidationStatus::Risky->value],
        ])->save();
    }

    /**
     * Results that have not been classified yet.
     */
    private function remainingUnchecked(Extraction $extraction): int
    {
        return $extraction->results()->whereNull('validation_status')->count();
    }

    /**
     * Called by the queue once the final attempt has failed.
     *
     * The counters are left exactly as the last successful batch wrote them,
     * because they are true. A task that died at 82% should read 82% and say it
     * failed, not be presented as either complete or empty.
     */
    public function failed(Throwable $exception): void
    {
        Extraction::query()
            ->whereKey($this->extractionId)
            ->update([
                'status' => ExtractionStatus::Failed->value,
                'completed_at' => now(),
                'error' => mb_substr(SensitiveData::redactText($exception->getMessage()), 0, 500),
            ]);

        // The account is no longer held, so its next task may start. Without this
        // one failure would park everything behind it indefinitely, which is a
        // considerably worse outcome than the failure itself.
        $extraction = Extraction::query()->find($this->extractionId);

        if ($extraction !== null) {
            app(PendingTaskQueue::class)->dispatchNext((int) $extraction->user_id);
        }
    }

    /**
     * A stalled validation is one the worker took but never finished.
     *
     * Re-queuing it is safe because every write here is an upsert or a recount: a
     * second pass converges on the same rows and the same counters rather than
     * compounding them.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addSeconds($this->timeout * 2);
    }
}

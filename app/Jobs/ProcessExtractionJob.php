<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Extraction\ExtractionStatus;
use App\Domain\Extraction\Extractor;
use App\Domain\System\Queue\WorkerBounds;
use App\Models\Extraction;
use App\Support\SensitiveData;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Processes one stored extraction on the database queue.
 *
 * Only the identifier crosses the queue boundary. The content is read back from
 * the database here, so a large paste cannot inflate the queued payload, the
 * `jobs` row, or every retry of it.
 *
 * The job owns the extraction's state transition. A worker that throws must
 * not leave a row claiming to be `completed`, and a customer must be able to
 * see that their extraction failed rather than watching it sit at `pending`
 * forever — so the transition to `processing` happens on pickup, and failure is
 * recorded before the exception is allowed to propagate to the queue's own
 * retry handling.
 */
class ProcessExtractionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Finite execution.
     *
     * Resolved from the same deployment limits the worker uses, and held below
     * the connection's `retry_after`, so a worker that overruns is killed and
     * retried rather than having the same job handed to a second worker while
     * this one still holds it.
     */
    public int $timeout;

    /**
     * Attempts before the queue gives up. Bounded by the deployment limit so a
     * permanently failing input cannot cycle through cron indefinitely.
     */
    public int $tries;

    public function __construct(public int $extractionId)
    {
        $bounds = WorkerBounds::resolve();

        $this->timeout = $bounds->jobTimeoutSeconds();
        $this->tries = $bounds->maxAttempts;
    }

    public function handle(Extractor $extractor): void
    {
        $extraction = Extraction::query()->find($this->extractionId);

        if ($extraction === null) {
            // The extraction was deleted between dispatch and pickup. There is
            // nothing to transition, and failing would put a row in failed_jobs
            // describing a record that no longer exists.
            throw new ModelNotFoundException('extraction '.$this->extractionId.' no longer exists');
        }

        $extraction->forceFill([
            'status' => ExtractionStatus::Processing->value,
            'started_at' => now(),
            'completed_at' => null,
            'error' => null,
        ])->save();

        try {
            $counts = $extractor->extract($extraction);
        } catch (Throwable $exception) {
            $extraction->forceFill([
                'status' => ExtractionStatus::Failed->value,
                'completed_at' => now(),
                // Reduced to something safe to persist and to show. Error text
                // routinely quotes configuration, and this column outlives the
                // request that wrote it.
                'error' => mb_substr(SensitiveData::redactText($exception->getMessage()), 0, 500),
            ])->save();

            throw $exception;
        }

        $extraction->forceFill([
            'status' => ExtractionStatus::Completed->value,
            'completed_at' => now(),
            'processed_count' => $counts['processed'],
            'found_count' => $counts['found'],
            'failed_count' => 0,
        ])->save();
    }

    /**
     * Called by the queue once the final attempt has failed.
     *
     * Records the outcome on the extraction itself so a customer can see it,
     * independently of the queue's own failed_jobs table. The raw exception
     * is not stored: `failed()` also receives it, and it can carry a traceback
     * with configuration values in it.
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
    }

    /**
     * A stalled extraction is one the worker took but never finished.
     *
     * Re-queuing it is safe because the work is idempotent: results are keyed
     * on (extraction_id, email), so a second pass converges on the same rows.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addSeconds($this->timeout * 2);
    }
}

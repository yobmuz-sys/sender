<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Extraction\DatabaseExtractionSource;
use App\Domain\Extraction\ExtractionSource;
use App\Domain\Extraction\ExtractionStatus;
use App\Domain\Extraction\Extractor;
use App\Domain\Extraction\FileExtractionSource;
use App\Domain\Extraction\Url\FetchedResource;
use App\Domain\Extraction\Url\SecureUrlFetcher;
use App\Domain\Extraction\Url\UrlFetchException;
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

    public function handle(Extractor $extractor, SecureUrlFetcher $fetcher): void
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

        // The temporary file is owned by this method. `finally` rather than the
        // success path alone, because a fetch that fails halfway through still
        // leaves a file behind, and one leaked per attempt would accumulate
        // across every retry of every job.
        $resource = null;
        $source = null;

        try {
            $source = $this->sourceFor($extraction, $fetcher, $resource);

            $counts = $extractor->extract($extraction, $source);
        } catch (UrlFetchException $exception) {
            // A refused URL is not a transient fault and not an extraction
            // failure worth retrying: the same URL will be refused identically
            // every time. Recording the category — rather than a transport
            // message, which describes this network rather than the user's
            // request — and marking it terminal here is what stops a
            // permanently invalid URL from cycling through the queue.
            $extraction->forceFill([
                'status' => ExtractionStatus::Failed->value,
                'completed_at' => now(),
                'error' => $exception->reason->value,
            ])->save();

            // Not rethrown: there is no retry that could succeed, and a job that
            // always fails would eventually land in failed_jobs as noise.
            return;
        } catch (Throwable $exception) {
            $extraction->forceFill([
                'status' => ExtractionStatus::Processing->value,
                'error' => $this->safeMessage($exception),
            ])->save();

            // Rethrown so the queue's own retry handling decides what happens
            // next. The status is deliberately left non-terminal: Laravel will
            // try again, and writing `failed` here would show a customer a
            // dead extraction while the system was still working on it.
            throw $exception;
        } finally {
            $resource?->remove();
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
     * Where the bytes for this extraction come from.
     *
     * Pasted text is already in the database. A URL is not: it is fetched here,
     * on the worker, under the full network policy — never during the request,
     * where the caller would be waiting on a third party.
     *
     * @param  FetchedResource|null  $resource  Receives the fetched file so the
     *                                          caller can remove it afterwards.
     */
    private function sourceFor(
        Extraction $extraction,
        SecureUrlFetcher $fetcher,
        ?FetchedResource &$resource,
    ): ExtractionSource {
        if ($extraction->source_type === 'url') {
            $resource = $fetcher->fetch((string) $extraction->source_ref);

            return new FileExtractionSource($resource->path);
        }

        return new DatabaseExtractionSource((string) ($extraction->content ?? ''));
    }

    /**
     * Called by the queue once the final attempt has failed.
     *
     * This is the only place `failed` is written. `failed()` is invoked by the
     * queue only when attempts are exhausted, so the transition is genuinely
     * terminal rather than a prediction.
     *
     * The raw exception is not stored: it can carry a traceback with
     * configuration values in it, and this column outlives the deployment that
     * wrote it.
     */
    public function failed(Throwable $exception): void
    {
        Extraction::query()
            ->whereKey($this->extractionId)
            ->update([
                'status' => ExtractionStatus::Failed->value,
                'completed_at' => now(),
                'error' => $this->safeMessage($exception),
            ]);
    }

    /**
     * A failure message reduced to something safe to persist and to show.
     */
    private function safeMessage(Throwable $exception): string
    {
        return mb_substr(SensitiveData::redactText($exception->getMessage()), 0, 500);
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

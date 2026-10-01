<?php

declare(strict_types=1);

namespace Tests\Feature\Fakes;

use App\Domain\Extraction\Extractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * A job that always throws, for proving the worker's failure accounting.
 *
 * Lives in the test suite rather than the application because nothing in the
 * product should ever dispatch it. It exists so the worker can be observed
 * giving up on work — the case that a processed-only counter would report as
 * if nothing had been wrong.
 */
class FailingExtractionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 10;

    public int $tries = 2;

    public function __construct(public int $extractionId) {}

    public function handle(Extractor $extractor): void
    {
        throw new RuntimeException('this job always fails');
    }
}

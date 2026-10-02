<?php

declare(strict_types=1);

namespace Tests\Feature\Fakes;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A job that succeeds and does nothing, for proving the worker's accounting.
 *
 * The real `ProcessExtractionJob` cannot be used for that: it dispatches a
 * second job, so a run that claims to have processed three jobs actually
 * processes six. That is correct product behaviour and the wrong measuring
 * instrument — these tests are about how the worker *counts*, and a job whose
 * only effect is to succeed counts exactly one thing.
 */
class SucceedingExtractionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 10;

    public int $tries = 2;

    public function __construct(public int $extractionId) {}

    public function handle(): void
    {
        //
    }
}

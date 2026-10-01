<?php

declare(strict_types=1);

namespace App\Domain\System\Queue;

/**
 * Job counts observed from the queue's own lifecycle events.
 *
 * Exists because queue depth cannot answer the question. A run that begins with
 * ten jobs, finishes all ten, and has two new jobs dispatched while it was
 * working ends at a depth of two — so depth arithmetic reports eight, for a
 * run that did ten. Retries distort it further: a job that fails and is retried
 * leaves the queue without having succeeded.
 *
 * Both figures are attached to a `scheduled_runs` row as durable operational
 * evidence, so a wrong number is not a cosmetic defect. It is the number an
 * operator reads when deciding whether the platform is doing its work.
 */
final class RunCounters
{
    /**
     * Jobs that completed successfully.
     */
    public int $processed = 0;

    /**
     * Jobs that exhausted their attempts and were given up on.
     *
     * Counted from the final-failure event, not the per-attempt exception, so
     * a retried-and-then-succeeded job is not reported as failed.
     */
    public int $failed = 0;
}

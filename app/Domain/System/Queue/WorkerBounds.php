<?php

declare(strict_types=1);

namespace App\Domain\System\Queue;

use App\Domain\System\Enums\DeploymentLimit;

/**
 * The bounds within which a worker invocation must operate.
 *
 * Every value is resolved from `DeploymentLimit`, so a deployment changes a
 * ceiling by editing configuration rather than by editing a command. The
 * worker never carries its own literal: a magic number here would be the first
 * thing to drift out of step with the reservation invariant it has to satisfy.
 *
 * The relationship that matters:
 *
 *     retry_after >= max_worker_runtime + reservation_margin
 *
 * A worker that outlives `retry_after` has its job handed to a second worker
 * while the first is still running, and the same extraction is processed twice.
 * `safe()` exists so the worker can refuse to start rather than create that
 * condition silently.
 */
final readonly class WorkerBounds
{
    public function __construct(
        public int $maxRuntimeSeconds,
        public int $maxJobs,
        public int $maxAttempts,
        public int $retryAfterSeconds,
        public int $reservationMarginSeconds,
    ) {}

    public static function resolve(?int $maxRuntimeOverride = null, ?int $maxJobsOverride = null): self
    {
        $maxRuntime = DeploymentLimit::MaxWorkerRuntimeSeconds->value();
        $maxJobs = (int) config('sender.capabilities.queue.max_jobs_per_run', 25);
        $retryAfter = (int) config('queue.connections.'.config('queue.default').'.retry_after', 0);

        return new self(
            // An override may only ever tighten the ceiling, never raise it.
            // Raising the runtime past the reservation window would defeat the
            // invariant; raising the batch size would let one cron entry run
            // far longer than the operator configured for.
            maxRuntimeSeconds: self::clamp($maxRuntimeOverride, $maxRuntime),
            maxJobs: self::clamp($maxJobsOverride, $maxJobs),
            maxAttempts: DeploymentLimit::MaxJobAttempts->value(),
            retryAfterSeconds: $retryAfter,
            reservationMarginSeconds: (int) config('sender.capabilities.queue.reservation_margin_seconds'),
        );
    }

    /**
     * Constrain an operator-supplied ceiling to at least 1 and at most the
     * configured value.
     *
     * A non-positive request is not honoured as zero: `--max-jobs=0` would be
     * passed straight to the worker and is not a meaningful thing to ask for.
     * The command reports the rejection rather than silently substituting a
     * number, because a cron entry quietly doing something other than what it
     * says is worse than one that refuses.
     */
    private static function clamp(?int $requested, int $ceiling): int
    {
        if ($requested === null) {
            return $ceiling;
        }

        return max(1, min($requested, $ceiling));
    }

    /**
     * The longest a single job may run.
     *
     * Strictly below `retry_after`, so a worker that overruns is killed and the
     * job retried rather than being executed concurrently elsewhere.
     */
    public function jobTimeoutSeconds(): int
    {
        $available = $this->retryAfterSeconds - $this->reservationMarginSeconds;

        return max(1, min($this->maxRuntimeSeconds, $available));
    }

    /**
     * Whether the reservation invariant currently holds for this connection.
     */
    public function safe(): bool
    {
        return $this->retryAfterSeconds >= $this->maxRuntimeSeconds + $this->reservationMarginSeconds;
    }

    /**
     * Why the worker cannot run, or null when it can.
     */
    public function unsafeReason(): ?string
    {
        if ($this->safe()) {
            return null;
        }

        return sprintf(
            'retry_after (%ds) must be at least max_worker_runtime_seconds (%ds) plus the '
            .'reservation margin (%ds); a worker that outlives its reservation has the same '
            .'job handed to a second worker and processes it twice',
            $this->retryAfterSeconds,
            $this->maxRuntimeSeconds,
            $this->reservationMarginSeconds,
        );
    }

    /**
     * Only meaningful for the database driver, which is the reservation this
     * invariant was written for.
     */
    public function appliesToDatabaseQueue(): bool
    {
        return config('queue.default') === 'database';
    }
}

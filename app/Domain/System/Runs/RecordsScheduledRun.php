<?php

declare(strict_types=1);

namespace App\Domain\System\Runs;

use Throwable;

/**
 * Records a scheduled command's execution in durable history.
 *
 * Present so every scheduled command produces the same operational evidence by
 * construction, rather than each one remembering to write a heartbeat. A
 * command that fails still leaves a record, which is the case an operator most
 * needs to see.
 *
 * The heartbeat concept this replaces was a cache-backed timestamp with no
 * outcome: it could not distinguish "cron ran and failed" from "cron ran".
 *
 * A run left in the Running state means the process died before it could close
 * its own record — a timeout, or a host terminating a long cron process. That
 * is a genuine signal rather than a gap, so nothing force-closes it.
 */
trait RecordsScheduledRun
{
    private ?ScheduledRun $scheduledRun = null;

    private ?RunRecorder $recorder = null;

    protected function recordRun(RunRecorder $recorder): void
    {
        $this->recorder = $recorder;
        $this->scheduledRun = $recorder->start($this->signature ?? static::class);
    }

    /**
     * Close the run as succeeded.
     */
    protected function completeRun(int $processed = 0, int $failed = 0): void
    {
        if ($this->scheduledRun !== null && $this->recorder !== null) {
            $this->recorder->succeed($this->scheduledRun, $processed, $failed);
        }
    }

    /**
     * Close the run as failed, preserving the message for diagnostics.
     *
     * Called from a catch block so a command that dies still records why.
     */
    protected function failRun(Throwable $exception): void
    {
        if ($this->scheduledRun !== null && $this->recorder !== null) {
            $this->recorder->fail($this->scheduledRun, $exception->getMessage());
        }
    }
}

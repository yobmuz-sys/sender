<?php

declare(strict_types=1);

namespace App\Domain\System\Runs;

use App\Domain\System\CapabilityCheck;
use App\Domain\System\Enums\CapabilitySubject;

/**
 * Derives capability state from recorded run history.
 *
 * Replaces the standalone heartbeat. A cache-backed timestamp says only
 * "something ran recently"; a run record says which command ran, whether it
 * succeeded, and when. The second can answer the question an operator actually
 * has, which is why the heartbeat was not worth keeping alongside it.
 *
 * The trade-off accepted here: clearing the cache no longer resets cron to
 * UNKNOWN, because the evidence is now durable. A forgotten observation cannot
 * outlast the entry that produced it.
 */
final class RunObserver
{
    public function __construct(
        private readonly RunRecorder $runs,
    ) {}

    /**
     * The command whose execution proves the platform scheduler is alive.
     */
    public function probeCommand(): string
    {
        return 'sender:heartbeat';
    }

    /**
     * Derive the cron capability from whether the probe command is running.
     */
    public function check(): CapabilityCheck
    {
        $latest = $this->runs->latest($this->probeCommand());
        $subject = CapabilitySubject::Cron;

        if ($latest === null) {
            return CapabilityCheck::unknown(
                'cron scheduler',
                'no scheduled run has ever been recorded',
                [
                    'Configure a cPanel Cron Job to call: php artisan sender:heartbeat',
                    'Background processing is not yet implemented; cron is only being observed at this stage.',
                ],
                $subject,
            );
        }

        if (! $latest->succeeded()) {
            return CapabilityCheck::degraded(
                'cron scheduler',
                sprintf('last run %s at %s', $latest->status->label(), $latest->started_at->toDateTimeString()),
                array_filter([
                    $latest->error === null ? null : 'Last error: '.$latest->error,
                    'Check the cPanel Cron Job log; the command is running but not succeeding.',
                ]),
                $subject,
            );
        }

        $age = $latest->finished_at === null
            ? null
            : now()->getTimestamp() - $latest->finished_at->getTimestamp();

        if ($age !== null && $age > $this->runs->freshAfterSeconds()) {
            return CapabilityCheck::degraded(
                'cron scheduler',
                sprintf('last successful run %ds ago (stale after %ds)', $age, $this->runs->freshAfterSeconds()),
                ['Check that the cPanel Cron Job is still scheduled and has not started failing.'],
                $subject,
            );
        }

        return CapabilityCheck::ready(
            'cron scheduler',
            sprintf('last successful run %ds ago', $age ?? 0),
            subject: $subject,
        );
    }

    /**
     * The most recent runs, for diagnostics.
     *
     * @return list<ScheduledRun>
     */
    public function recent(int $limit = 5): array
    {
        return $this->runs->recent($this->probeCommand(), $limit);
    }
}

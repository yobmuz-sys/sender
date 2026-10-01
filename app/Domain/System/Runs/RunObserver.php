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
     *
     * This was `sender:heartbeat`, which existed only because nothing else ran
     * from cron. Now that `sender:work` does the platform's real work, it is
     * the authoritative signal: a cron entry that succeeds proves both that the
     * scheduler fires *and* that the platform got something done, whereas the
     * heartbeat only proved the first.
     *
     * This matters most for an installation that never migrated. A deployment
     * still calling only the heartbeat would otherwise be reported as having a
     * healthy scheduler while its queue is never drained, which is the exact
     * state Stage 3D was built to detect.
     */
    public function probeCommand(): string
    {
        return 'sender:work';
    }

    /**
     * Every command whose runs are shown in the run history.
     *
     * Wider than `probeCommand()` on purpose. The capability *verdict* comes
     * from the worker alone, but history should not hide a deployment's own
     * records just because they are now legacy.
     *
     * @return list<string>
     */
    public function probeCommands(): array
    {
        return ['sender:work', 'sender:verify-url', 'sender:heartbeat'];
    }

    /**
     * The most recent run of the authoritative probe command.
     *
     * Deliberately does not consider the other commands. See `probeCommand()`.
     */
    public function latestProbeRun(): ?ScheduledRun
    {
        return $this->runs->latest($this->probeCommand());
    }

    /**
     * Derive the cron capability from whether the probe command is running.
     */
    public function check(): CapabilityCheck
    {
        $latest = $this->latestProbeRun();
        $subject = CapabilitySubject::Cron;

        if ($latest === null) {
            return CapabilityCheck::unknown(
                'cron scheduler',
                'no scheduled run has ever been recorded',
                [
                    'Configure a cPanel Cron Job to call: php artisan sender:work',
                    'That command processes queued work, exits, and records the run.',
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
     * Across every probe command, so a deployment still calling the heartbeat
     * does not appear to have no run history at all.
     *
     * @return list<ScheduledRun>
     */
    public function recent(int $limit = 5): array
    {
        $runs = [];

        foreach ($this->probeCommands() as $command) {
            foreach ($this->runs->recent($command, $limit) as $run) {
                $runs[] = $run;
            }
        }

        usort(
            $runs,
            static fn (ScheduledRun $a, ScheduledRun $b): int => $b->started_at <=> $a->started_at,
        );

        return array_slice($runs, 0, $limit);
    }
}

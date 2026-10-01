<?php

declare(strict_types=1);

namespace App\Domain\System\Runs;

use App\Support\SensitiveData;
use Illuminate\Database\Eloquent\Collection;

/**
 * Records and reads scheduled/worker run history.
 *
 * The write side exists so that any scheduled command can leave durable
 * evidence of executing, and the read side exists so cron health and operational
 * diagnostics can be derived from that evidence instead of from a separate
 * heartbeat flag.
 */
final class RunRecorder
{
    /**
     * Record that a command has begun executing.
     *
     * The row is written as Running so a process killed mid-flight leaves
     * evidence rather than vanishing from the history.
     */
    public function start(string $command): ScheduledRun
    {
        return ScheduledRun::query()->create([
            'command' => $command,
            'started_at' => now(),
            'status' => RunStatus::Running->value,
        ]);
    }

    public function succeed(ScheduledRun $run, int $processed = 0, int $failed = 0): ScheduledRun
    {
        return $this->finish($run, RunStatus::Succeeded, $processed, $failed);
    }

    public function fail(ScheduledRun $run, string $error, int $processed = 0, int $failed = 0): ScheduledRun
    {
        return $this->finish($run, RunStatus::Failed, $processed, $failed, $error);
    }

    /**
     * @param  list<ScheduledRun>  $runs
     * @return list<ScheduledRun>
     */
    public function recent(string $command, int $limit = 10): array
    {
        /** @var Collection<int, ScheduledRun> $runs */
        $runs = ScheduledRun::query()
            ->where('command', $command)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $runs->all();
    }

    public function latest(string $command): ?ScheduledRun
    {
        return ScheduledRun::latestFor($command);
    }

    /**
     * How recently a command must have finished for it to count as working.
     */
    public function freshAfterSeconds(): int
    {
        return (int) config('sender.capabilities.cron.stale_after_seconds', 900);
    }

    /**
     * Whether the command has completed successfully within the fresh window.
     *
     * Deliberately requires a *succeeded* run rather than any run. A command
     * that has started failing is not working, and treating its attempts as
     * liveness would make a broken deployment look healthy precisely when
     * somebody is trying to diagnose it.
     */
    public function isFresh(string $command): bool
    {
        $latest = $this->latest($command);

        return $latest !== null
            && $latest->succeeded()
            && $latest->finished_at !== null
            && $latest->finished_at->getTimestamp() >= now()->getTimestamp() - $this->freshAfterSeconds();
    }

    private function finish(
        ScheduledRun $run,
        RunStatus $status,
        int $processed,
        int $failed,
        ?string $error = null,
    ): ScheduledRun {
        $finishedAt = now();

        $run->forceFill([
            'finished_at' => $finishedAt,
            'status' => $status->value,
            'duration_ms' => (int) round(($finishedAt->getTimestamp() - $run->started_at->getTimestamp()) * 1000),
            'processed_count' => $processed,
            'failed_count' => $failed,
            'error' => $error === null ? null : $this->sanitise($error),
        ])->save();

        return $run;
    }

    /**
     * Reduce a failure message to something safe to persist.
     *
     * Error text routinely quotes the configuration that caused the failure,
     * including a password, and a run record outlives the deployment that wrote
     * it. Key-based redaction is not enough here: the key is `error`, which
     * says nothing about the content, so the text is scrubbed on content too.
     */
    private function sanitise(string $error): string
    {
        return mb_substr(SensitiveData::redactText($error), 0, 1000);
    }
}

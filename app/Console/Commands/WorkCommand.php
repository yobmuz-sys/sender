<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\System\Enums\Subsystem;
use App\Domain\System\Flags\SubsystemFlagRegistry;
use App\Domain\System\Queue\RunCounters;
use App\Domain\System\Queue\WorkerBounds;
use App\Domain\System\Runs\RecordsScheduledRun;
use App\Domain\System\Runs\RunRecorder;
use Illuminate\Console\Command;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The command a cPanel Cron Job points at to actually do work.
 *
 * Laravel's database queue is the queue. What it does not provide is a way to
 * run one of them from cron, and that gap was invisible until a real workload
 * existed: with `QUEUE_CONNECTION=sync` in the test environment every dispatch
 * executed inline, so the suite proved the application path while production
 * queued jobs that nothing ever picked up.
 *
 * This command closes that gap. It is deliberately not a daemon:
 *
 *   - it processes a bounded number of jobs and exits
 *   - it stops at a bounded runtime and exits
 *   - it never loops forever
 *
 * which is what makes it safe to hand to a cron entry on shared hosting, where
 * a resident process would be killed when the invocation ends and cannot be
 * supervised.
 *
 * The work itself is done by Laravel's own `queue:work`, driven with those
 * bounds. Reimplementing queue internals would add a second implementation to
 * keep correct; the only things this command owns are the bounds, the operator
 * kill switch, and the run evidence.
 */
class WorkCommand extends Command
{
    use RecordsScheduledRun;

    protected $signature = 'sender:work
        {--max-jobs= : Process at most this many jobs; may only tighten the configured ceiling}
        {--max-runtime= : Exit after this many seconds; may only tighten the configured ceiling}';

    protected $description = 'Process a bounded batch of queued jobs and exit (point your cPanel Cron Job here)';

    public function handle(RunRecorder $recorder, SubsystemFlagRegistry $flags): int
    {
        if (! $flags->enabled(Subsystem::Cron)) {
            // Deliberately no run record. An operator who has switched the
            // scheduler off should not see a stream of "succeeded" runs, and
            // this must not be mistaken for evidence that cron is working.
            $this->warn('Scheduled processing is disabled by the operator. Nothing was processed.');

            return self::SUCCESS;
        }

        if (config('queue.default') !== 'database') {
            $this->error('This worker drains the database queue, but QUEUE_CONNECTION is '.config('queue.default').'.');
            $this->line('Set QUEUE_CONNECTION=database for this installation, or leave it as sync to run inline.');

            return self::FAILURE;
        }

        if (! Schema::hasTable('jobs')) {
            $this->error('The jobs table is missing. Run: php artisan migrate');

            return self::FAILURE;
        }

        $maxRuntime = $this->option('max-runtime') !== null ? (int) $this->option('max-runtime') : null;
        $maxJobs = $this->option('max-jobs') !== null ? (int) $this->option('max-jobs') : null;

        // A non-positive request is refused rather than quietly replaced with
        // something else. A cron entry that does not do what it says is worse
        // than one that declines to run.
        foreach (['--max-jobs' => $maxJobs, '--max-runtime' => $maxRuntime] as $option => $value) {
            if ($value !== null && $value < 1) {
                $this->error($option.' must be at least 1.');

                return self::FAILURE;
            }
        }

        $bounds = WorkerBounds::resolve(maxRuntimeOverride: $maxRuntime, maxJobsOverride: $maxJobs);

        // An override above the configured ceiling is clamped. Tell the
        // operator, because silently doing less than they asked for is its own
        // kind of surprise.
        if ($maxJobs !== null && $maxJobs > $bounds->maxJobs) {
            $this->warn(sprintf(
                '--max-jobs=%d exceeds the configured ceiling; using %d.',
                $maxJobs,
                $bounds->maxJobs,
            ));
        }

        if ($maxRuntime !== null && $maxRuntime > $bounds->maxRuntimeSeconds) {
            $this->warn(sprintf(
                '--max-runtime=%d exceeds the configured ceiling; using %ds.',
                $maxRuntime,
                $bounds->maxRuntimeSeconds,
            ));
        }

        // Refusing to start is the point. Starting anyway would let a job be
        // handed to a second worker while this one still holds it, and the same
        // extraction would be processed twice.
        if ($bounds->appliesToDatabaseQueue() && ! $bounds->safe()) {
            $this->error('Refusing to start: the queue reservation invariant does not hold.');
            $this->line((string) $bounds->unsafeReason());

            return self::FAILURE;
        }

        $this->recordRun($recorder);

        try {
            // Counted from the queue's own lifecycle events rather than by
            // subtracting queue depth before and after.
            //
            // Depth arithmetic cannot be right: jobs dispatched *during* the run
            // inflate the "after" figure, and a job that fails and is retried
            // leaves the queue without ever having succeeded. Either way the
            // number recorded as durable operational evidence would be wrong,
            // and this record is what an operator reads when asking whether the
            // platform did its work.
            $counter = new RunCounters;
            $this->observeRun($counter);

            $exit = $this->call('queue:work', [
                '--stop-when-empty' => true,
                '--max-time' => $bounds->maxRuntimeSeconds,
                '--max-jobs' => $bounds->maxJobs,
                '--timeout' => $bounds->jobTimeoutSeconds(),
                '--tries' => $bounds->maxAttempts,
                '--queue' => (string) config('queue.connections.'.config('queue.default').'.queue', 'default'),
            ]);
        } catch (Throwable $exception) {
            $this->failRun($exception);

            throw $exception;
        }

        $queuedAfter = $this->countRows('jobs');

        if ($exit !== self::SUCCESS) {
            $this->failRunWith('queue:work exited with status '.$exit, $counter->processed, $counter->failed);

            $this->error('The worker stopped with status '.$exit.'.');

            return self::FAILURE;
        }

        $this->completeRun($counter->processed, $counter->failed);

        $this->info(sprintf(
            'Completed %d job(s) and gave up on %d within %ds and %d job(s); %d still queued.',
            $counter->processed,
            $counter->failed,
            $bounds->maxRuntimeSeconds,
            $bounds->maxJobs,
            $queuedAfter,
        ));

        return self::SUCCESS;
    }

    /**
     * Observe the queue's own job events for the duration of this run.
     *
     * `JobProcessed` fires once per job that completes. `JobFailed` fires only
     * when a job has exhausted its attempts and is moved to the failed table —
     * which is why it, and not the per-attempt exception event, is the honest
     * measure of work given up on.
     */
    private function observeRun(RunCounters $counter): void
    {
        Event::listen(JobProcessed::class, static function () use ($counter): void {
            $counter->processed++;
        });

        Event::listen(JobFailed::class, static function () use ($counter): void {
            $counter->failed++;
        });
    }

    /**
     * Rows in a queue table, or zero if it is not there yet.
     *
     * Counting is best-effort: a missing or unreadable table must not stop the
     * worker, because the work itself is the point and the run record still
     * gets written.
     */
    private function countRows(string $table): int
    {
        try {
            return Schema::hasTable($table) ? DB::table($table)->count() : 0;
        } catch (Throwable) {
            return 0;
        }
    }
}

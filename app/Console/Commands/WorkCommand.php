<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\System\Enums\Subsystem;
use App\Domain\System\Flags\SubsystemFlagRegistry;
use App\Domain\System\Queue\WorkerBounds;
use App\Domain\System\Runs\RecordsScheduledRun;
use App\Domain\System\Runs\RunRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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

        $bounds = WorkerBounds::resolve(
            maxRuntimeOverride: $this->option('max-runtime') !== null ? (int) $this->option('max-runtime') : null,
            maxJobsOverride: $this->option('max-jobs') !== null ? (int) $this->option('max-jobs') : null,
        );

        // Refusing to start is the point. Starting anyway would let a job be
        // handed to a second worker while this one still holds it, and the same
        // extraction would be processed twice.
        if ($bounds->appliesToDatabaseQueue() && ! $bounds->safe()) {
            $this->error('Refusing to start: the queue reservation invariant does not hold.');
            $this->line((string) $bounds->unsafeReason());

            return self::FAILURE;
        }

        $this->recordRun($recorder);

        $queuedBefore = $this->countRows('jobs');

        try {
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
        $processed = max(0, $queuedBefore - $queuedAfter);
        $failed = $this->countRows('failed_jobs');

        if ($exit !== self::SUCCESS) {
            $this->failRunWith('queue:work exited with status '.$exit, $processed, $failed);

            $this->error('The worker stopped with status '.$exit.'.');

            return self::FAILURE;
        }

        $this->completeRun($processed, $failed);

        $this->info(sprintf(
            'Processed %d job(s) within %ds and %d job attempt(s); %d still queued.',
            $processed,
            $bounds->maxRuntimeSeconds,
            $bounds->maxJobs,
            $queuedAfter,
        ));

        return self::SUCCESS;
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

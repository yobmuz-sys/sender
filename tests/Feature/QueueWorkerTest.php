<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\System\Enums\DeploymentLimit;
use App\Domain\System\Enums\Subsystem;
use App\Domain\System\Flags\SubsystemFlagRegistry;
use App\Domain\System\Queue\WorkerBounds;
use App\Domain\System\Runs\RunRecorder;
use App\Jobs\ProcessExtractionJob;
use App\Models\Extraction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The production queue path.
 *
 * Every other extraction test runs with `QUEUE_CONNECTION=sync`, which executes
 * a dispatch inline and therefore proves the application path while proving
 * nothing about the worker. That gap is exactly how the repository came to
 * contain a dispatched job that nothing ever ran: the suite was green and
 * production was not.
 *
 * These tests drive the database queue and the worker command, which is the
 * path a cPanel cron entry actually takes.
 */
class QueueWorkerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The database driver is the configuration under test, so it has to be
        // the one actually in force rather than the testing default.
        config()->set('queue.default', 'database');

        if (! Schema::hasTable('jobs')) {
            $this->artisan('migrate')->run();
        }
    }

    public function test_a_submitted_extraction_lands_on_the_database_queue_rather_than_running_inline(): void
    {
        Queue::fake();

        $this->actingAs(User::factory()->create())
            ->post('/extractor', ['source_type' => 'paste', 'content' => 'someone@example.com'])
            ->assertRedirect();

        // Sync would have executed the job already. Asserting the payload exists
        // is what distinguishes "queued for a worker" from "ran inline".
        Queue::assertPushed(ProcessExtractionJob::class);
    }

    public function test_the_worker_processes_a_queued_extraction_and_persists_results(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/extractor', [
                'source_type' => 'paste',
                'content' => "alpha@example.com\nbeta@example.com\n",
            ])
            ->assertRedirect();

        // A real row on the real queue, not a faked dispatch.
        $this->assertSame(1, DB::table('jobs')->count(), 'the job must be waiting on the database queue');
        $this->assertSame(0, DB::table('extraction_results')->count());

        $this->artisan('sender:work')->assertSuccessful();

        $this->assertSame(0, DB::table('jobs')->count(), 'the worker must drain what it claimed');
        $this->assertDatabaseCount('extraction_results', 2);

        $extraction = Extraction::query()->firstOrFail();

        $this->assertSame('completed', $extraction->status->value);
        $this->assertSame(2, $extraction->found_count);
        $this->assertNotNull($extraction->started_at);
        $this->assertNotNull($extraction->completed_at);
    }

    public function test_a_worker_invocation_records_durable_run_evidence(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/extractor', ['source_type' => 'paste', 'content' => 'someone@example.com'])
            ->assertRedirect();

        $this->artisan('sender:work')->assertSuccessful();

        // Evidence that the workload command itself ran, not that a heartbeat
        // reported in. An operator asking "is my extraction stuck?" is answered
        // by this row.
        $run = app(RunRecorder::class)->latest('sender:work');

        $this->assertNotNull($run, 'sender:work must leave a scheduled_runs record');
        $this->assertTrue($run->succeeded());
        $this->assertNotNull($run->finished_at);
        $this->assertSame(1, $run->processed_count);
        $this->assertNotNull($run->duration_ms);
    }

    public function test_running_the_worker_twice_does_not_duplicate_results(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/extractor', [
                'source_type' => 'paste',
                'content' => "alpha@example.com\nalpha@example.com\nbeta@example.com\n",
            ])
            ->assertRedirect();

        $extraction = Extraction::query()->firstOrFail();

        // First pass through the real worker.
        $this->artisan('sender:work')->assertSuccessful();
        $this->assertDatabaseCount('extraction_results', 2);

        // Re-queue and process the identical extraction again. Idempotency is
        // enforced by the unique constraint, not by an exists() check, so this
        // is safe even if two workers overlapped.
        ProcessExtractionJob::dispatch($extraction->id);
        $this->artisan('sender:work')->assertSuccessful();

        $this->assertDatabaseCount('extraction_results', 2);
        $this->assertSame(2, $extraction->refresh()->found_count);
    }

    public function test_a_worker_run_is_bounded_by_the_configured_job_ceiling(): void
    {
        $extraction = Extraction::factory()->create([
            'user_id' => User::factory()->create()->id,
            'status' => 'pending',
            'content' => 'someone@example.com',
        ]);

        // Three jobs waiting, but the run may only take one.
        for ($i = 0; $i < 3; $i++) {
            ProcessExtractionJob::dispatch($extraction->id);
        }

        $this->assertSame(3, DB::table('jobs')->count());

        config()->set('sender.capabilities.queue.max_jobs_per_run', 1);

        $this->artisan('sender:work')->assertSuccessful();

        $remaining = DB::table('jobs')->count();

        $this->assertLessThan(3, $remaining, 'the worker must stop at its ceiling rather than draining');
        $this->assertGreaterThan(0, $remaining);
    }

    public function test_the_worker_refuses_to_start_when_the_reservation_invariant_does_not_hold(): void
    {
        // retry_after below runtime + margin. Starting anyway would let the same
        // job be handed to a second worker while this one holds it.
        config()->set('queue.connections.database.retry_after', 10);

        $bounds = WorkerBounds::resolve();

        $this->assertFalse($bounds->safe());
        $this->assertNotNull($bounds->unsafeReason());

        $this->artisan('sender:work')
            ->expectsOutputToContain('reservation invariant')
            ->assertFailed();

        $this->assertDatabaseMissing('scheduled_runs', ['command' => 'sender:work']);
    }

    public function test_the_job_timeout_stays_below_the_reservation_window(): void
    {
        $bounds = WorkerBounds::resolve();

        $this->assertTrue($bounds->safe(), 'the shipped configuration must satisfy the invariant');
        $this->assertLessThan(
            $bounds->retryAfterSeconds,
            $bounds->jobTimeoutSeconds(),
            'a job may not be permitted to outlive its own reservation',
        );

        // And the job actually carries that timeout.
        $job = new ProcessExtractionJob(1);

        $this->assertSame($bounds->jobTimeoutSeconds(), $job->timeout);
        $this->assertSame($bounds->maxAttempts, $job->tries);
    }

    public function test_a_worker_runtime_override_can_only_tighten_the_ceiling(): void
    {
        // Raising it past the configured ceiling would defeat the invariant,
        // so an override is clamped rather than honoured.
        $loose = WorkerBounds::resolve(maxRuntimeOverride: 100_000);

        $this->assertSame(
            DeploymentLimit::MaxWorkerRuntimeSeconds->value(),
            $loose->maxRuntimeSeconds,
        );

        $tight = WorkerBounds::resolve(maxRuntimeOverride: 5);

        $this->assertSame(5, $tight->maxRuntimeSeconds);
    }

    public function test_the_worker_does_nothing_when_the_operator_has_disabled_the_scheduler(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/extractor', ['source_type' => 'paste', 'content' => 'someone@example.com'])
            ->assertRedirect();

        app(SubsystemFlagRegistry::class)
            ->disable(Subsystem::Cron);

        $this->artisan('sender:work')->assertSuccessful();

        $this->assertSame(1, DB::table('jobs')->count(), 'a disabled scheduler must not process work');

        // And it must not leave a succeeded run behind, which would read as
        // evidence that cron is working.
        $this->assertDatabaseMissing('scheduled_runs', ['command' => 'sender:work']);
    }

    public function test_the_worker_refuses_a_non_database_queue(): void
    {
        config()->set('queue.default', 'sync');

        $this->artisan('sender:work')
            ->expectsOutputToContain('QUEUE_CONNECTION')
            ->assertFailed();
    }

    public function test_an_empty_queue_is_a_successful_run(): void
    {
        // Cron fires whether or not there is work. Reporting failure for an
        // empty queue would train an operator to ignore the run history.
        $this->artisan('sender:work')->assertSuccessful();

        $this->assertDatabaseHas('scheduled_runs', ['command' => 'sender:work']);
    }
}

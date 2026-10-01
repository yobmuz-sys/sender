<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\System\Enums\CapabilityStatus;
use App\Domain\System\Runs\RunObserver;
use App\Domain\System\Runs\RunRecorder;
use App\Domain\System\Runs\RunStatus;
use App\Domain\System\Runs\ScheduledRun;
use Tests\TestCase;

/**
 * Cron capability derived from real executions.
 *
 * The probe used to be `sender:heartbeat`, because at Stage 3A that was the
 * only thing cron ran. Stage 3D gave cron real work, so the probe moved to the
 * worker: a scheduler that fires and successfully processes the queue is
 * stronger evidence than one that fires and does nothing.
 */
class RunObserverTest extends TestCase
{
    public function test_the_worker_is_the_primary_scheduler_evidence(): void
    {
        $this->assertSame('sender:work', app(RunObserver::class)->probeCommand());
    }

    public function test_the_heartbeat_is_still_counted_as_evidence(): void
    {
        // An installation still calling the heartbeat must not be reported as
        // having no run history at all.
        $this->assertContains('sender:heartbeat', app(RunObserver::class)->probeCommands());
    }

    public function test_the_cron_capability_is_unknown_before_anything_has_run(): void
    {
        $check = app(RunObserver::class)->check();

        $this->assertSame(CapabilityStatus::Unknown, $check->capability);
        $this->assertStringContainsString('sender:work', implode(' ', $check->remedies));
    }

    public function test_a_successful_worker_run_makes_the_cron_capability_ready(): void
    {
        $recorder = app(RunRecorder::class);
        $run = $recorder->start('sender:work');
        $recorder->succeed($run, 3, 0);

        $check = app(RunObserver::class)->check();

        $this->assertSame(CapabilityStatus::Ready, $check->capability);
    }

    public function test_a_failing_worker_run_degrades_rather_than_reports_cron_as_healthy(): void
    {
        // The reason the probe moved off the heartbeat: a queue misconfigured so
        // that every worker run fails is not a working scheduler.
        $recorder = app(RunRecorder::class);
        $run = $recorder->start('sender:work');
        $recorder->fail($run, 'jobs table missing');

        $check = app(RunObserver::class)->check();

        $this->assertSame(CapabilityStatus::Degraded, $check->capability);
    }

    public function test_a_stale_successful_run_degrades(): void
    {
        $recorder = app(RunRecorder::class);
        $run = $recorder->start('sender:work');
        $recorder->succeed($run);

        // Backdate the finish so it falls outside the fresh window.
        ScheduledRun::query()->whereKey($run->id)->update([
            'finished_at' => now()->subSeconds($recorder->freshAfterSeconds() + 60),
        ]);

        $this->assertSame(CapabilityStatus::Degraded, app(RunObserver::class)->check()->capability);
    }

    public function test_runs_from_both_probe_commands_are_shown_together(): void
    {
        $recorder = app(RunRecorder::class);

        $heartbeat = $recorder->start('sender:heartbeat');
        $recorder->succeed($heartbeat);
        ScheduledRun::query()->whereKey($heartbeat->id)->update(['started_at' => now()->subMinute()]);

        $work = $recorder->start('sender:work');
        $recorder->succeed($work);

        $commands = array_map(
            static fn (ScheduledRun $run): string => $run->command,
            app(RunObserver::class)->recent(10),
        );

        $this->assertContains('sender:work', $commands);
        $this->assertContains('sender:heartbeat', $commands);
    }

    public function test_the_recorded_command_name_excludes_option_definitions(): void
    {
        config()->set('queue.default', 'database');

        $this->artisan('sender:work')->assertSuccessful();

        $run = ScheduledRun::query()->where('command', 'like', 'sender%')->firstOrFail();

        // `sender:work` carries two options. Recording the whole signature
        // would mean nothing ever matched it when history was queried by name.
        $this->assertSame('sender:work', $run->command);
        $this->assertStringNotContainsString('--', $run->command);
    }

    public function test_a_run_left_running_is_visible_as_evidence_of_a_killed_process(): void
    {
        $recorder = app(RunRecorder::class);
        $run = $recorder->start('sender:work');

        $this->assertSame(RunStatus::Running, $run->status);

        // Nothing force-closes it: a row stuck at Running means the host killed
        // the process, which is itself the signal.
        $this->assertNull($run->refresh()->finished_at);
    }
}

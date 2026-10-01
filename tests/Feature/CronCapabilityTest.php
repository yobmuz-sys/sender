<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\System\Capabilities\CapabilityRegistry;
use App\Domain\System\Contracts\HostInspector;
use App\Domain\System\Enums\CapabilityStatus;
use App\Domain\System\Enums\CapabilitySubject;
use App\Domain\System\Mail\SmtpCapability;
use App\Domain\System\Runs\RunObserver;
use App\Domain\System\Runs\RunRecorder;
use App\Domain\System\Runs\ScheduledRun;
use Tests\TestCase;

/**
 * cPanel exposes no API that answers "is cron configured?", so the capability
 * can only ever be inferred from an observed execution. These tests pin the
 * states that inference must produce, and in particular that the platform does
 * not claim cron works before it has ever seen it.
 */
class CronCapabilityTest extends TestCase
{
    public function test_cron_is_unknown_before_any_run_is_recorded(): void
    {
        $this->assertNull(app(RunRecorder::class)->latest('sender:heartbeat'));

        $this->assertSame(CapabilityStatus::Unknown, app(RunObserver::class)->check()->capability);

        $this->assertSame(
            CapabilityStatus::Unknown,
            $this->cronStatus(),
        );
    }

    public function test_the_heartbeat_command_moves_cron_to_ready(): void
    {
        $this->artisan('sender:heartbeat')->assertSuccessful();

        $this->assertNotNull(app(RunRecorder::class)->latest('sender:heartbeat'));

        $this->assertSame(CapabilityStatus::Ready, $this->cronStatus());
    }

    public function test_repeated_runs_are_recorded_individually(): void
    {
        $this->artisan('sender:heartbeat')->assertSuccessful();
        $this->artisan('sender:heartbeat')->assertSuccessful();
        $this->artisan('sender:heartbeat')->assertSuccessful();

        // Each execution is evidence in its own right, which a single
        // overwritten timestamp could never be.
        $this->assertSame(3, ScheduledRun::query()->where('command', 'sender:heartbeat')->count());
    }

    public function test_a_stale_run_degrades_rather_than_failing(): void
    {
        $this->artisan('sender:heartbeat')->assertSuccessful();

        $this->travel(2)->hours();

        $this->assertSame(CapabilityStatus::Degraded, $this->cronStatus());
    }

    public function test_a_failing_run_degrades_and_explains_itself(): void
    {
        $recorder = app(RunRecorder::class);
        $run = $recorder->start('sender:heartbeat');
        $recorder->fail($run, 'connection refused by mail.example.com:587');

        $check = app(RunObserver::class)->check();

        $this->assertSame(CapabilityStatus::Degraded, $check->capability);
        $this->assertStringContainsString('connection refused', implode(' ', $check->remedies));
    }

    public function test_a_recent_failure_is_not_mistaken_for_liveness(): void
    {
        // A command that has started failing is not working. Treating its
        // attempts as liveness would make a broken deployment look healthy
        // exactly when somebody is trying to diagnose it.
        $recorder = app(RunRecorder::class);
        $recorder->fail($recorder->start('sender:heartbeat'), 'boom');

        $this->assertFalse($recorder->isFresh('sender:heartbeat'));
        $this->assertNotSame(CapabilityStatus::Ready, $this->cronStatus());
    }

    public function test_cron_is_never_reported_unavailable_without_evidence(): void
    {
        $observer = app(RunObserver::class);

        $this->assertSame(CapabilityStatus::Unknown, $observer->check()->capability);

        $recorder = app(RunRecorder::class);
        $recorder->succeed($recorder->start('sender:heartbeat'));

        $this->assertSame(CapabilityStatus::Ready, $observer->check()->capability);
    }

    /**
     * Resolve cron status through a freshly built registry.
     *
     * Capability state is memoised for the life of a request by design, so
     * every consumer must agree. A test that changes the world afterwards
     * therefore has to ask for a new one.
     */
    private function cronStatus(): CapabilityStatus
    {
        return (new CapabilityRegistry(
            app(HostInspector::class),
            app(RunObserver::class),
            app(SmtpCapability::class),
        ))->status(CapabilitySubject::Cron);
    }
}

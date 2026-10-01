<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\System\Capabilities\CapabilityRegistry;
use App\Domain\System\Enums\CapabilityStatus;
use App\Domain\System\Enums\CapabilitySubject;
use App\Domain\System\Services\CronHeartbeat;
use App\Domain\System\Services\HostCapabilityInspector;
use Tests\TestCase;

/**
 * cPanel exposes no API that answers "is cron configured?", so the capability
 * can only ever be inferred from an observed execution. These tests pin the
 * three states that inference must produce, and in particular that the
 * platform does not claim cron works before it has ever seen it.
 */
class CronCapabilityTest extends TestCase
{
    public function test_cron_is_unknown_before_any_heartbeat(): void
    {
        $this->assertNull(app(CronHeartbeat::class)->lastSeen());

        $this->assertSame(
            CapabilityStatus::Unknown,
            app(CapabilityRegistry::class)->status(CapabilitySubject::Cron),
        );
    }

    public function test_the_heartbeat_command_moves_cron_to_ready(): void
    {
        $this->artisan('sender:heartbeat')->assertSuccessful();

        $this->assertNotNull(app(CronHeartbeat::class)->lastSeen());

        $this->assertSame(
            CapabilityStatus::Ready,
            app(CapabilityRegistry::class)->status(CapabilitySubject::Cron),
        );
    }

    public function test_repeated_heartbeats_do_not_accumulate_records(): void
    {
        $this->artisan('sender:heartbeat')->assertSuccessful();
        $this->artisan('sender:heartbeat')->assertSuccessful();
        $this->artisan('sender:heartbeat')->assertSuccessful();

        $this->assertNotNull(app(CronHeartbeat::class)->lastSeen());
    }

    public function test_a_stale_heartbeat_degrades_rather_than_failing(): void
    {
        app(CronHeartbeat::class)->record();

        // Age the heartbeat past the threshold the way the next request would
        // see it, then build a fresh registry: capability state is memoised per
        // request precisely so consumers agree, which means a test has to ask
        // for a new one.
        app(CronHeartbeat::class)->record();
        $this->travel(1)->hours();

        config()->set('sender.capabilities.cron.stale_after_seconds', 900);

        $this->assertSame(
            CapabilityStatus::Degraded,
            $this->freshRegistry()->status(CapabilitySubject::Cron),
        );
    }

    private function freshRegistry(): CapabilityRegistry
    {
        return new CapabilityRegistry(
            app(HostCapabilityInspector::class),
            app(CronHeartbeat::class),
        );
    }

    public function test_cron_is_never_reported_unavailable_without_evidence(): void
    {
        $heartbeat = app(CronHeartbeat::class);

        $this->assertSame(CapabilityStatus::Unknown, $heartbeat->check()->capability);

        $heartbeat->record();
        $this->assertSame(CapabilityStatus::Ready, $heartbeat->check()->capability);
    }
}

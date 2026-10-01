<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\System\Capabilities\CapabilityRegistry;
use App\Domain\System\CapabilityCheck;
use App\Domain\System\Enums\CapabilityStatus;
use App\Domain\System\Enums\CapabilitySubject;
use App\Domain\System\Enums\DeploymentLimit;
use App\Domain\System\Services\HostCapabilityInspector;
use Tests\TestCase;

/**
 * The database queue decides a job was abandoned by comparing its reservation
 * age against retry_after, and will hand that job to another worker. So a
 * reservation window shorter than the permitted worker runtime permits the same
 * job to execute twice concurrently.
 *
 * The literal numbers matter less than the relationship: the test asserts the
 * invariant, so a future config change that recreates the hazard fails here
 * rather than in production.
 */
class QueueReservationTest extends TestCase
{
    /**
     * The suite runs with QUEUE_CONNECTION=sync, where a reservation window
     * cannot exist. Every case here therefore selects the database driver,
     * which is what a real deployment uses.
     */
    private function reservationCheck(): CapabilityCheck
    {
        config()->set('queue.default', 'database');

        $checks = array_filter(
            app(HostCapabilityInspector::class)->inspect()->checks,
            static fn ($check): bool => $check->name === 'queue reservation window',
        );

        $this->assertCount(1, $checks, 'the inspector must report exactly one reservation check');

        return array_values($checks)[0];
    }

    public function test_the_shipped_configuration_satisfies_the_invariant(): void
    {
        $this->assertSame(CapabilityStatus::Ready, $this->reservationCheck()->capability);
    }

    public function test_the_reservation_window_must_exceed_the_worker_runtime(): void
    {
        config()->set('queue.connections.database.retry_after', 90);

        $this->assertSame(CapabilityStatus::Unavailable, $this->reservationCheck()->capability);
    }

    public function test_making_the_worker_runtime_longer_than_the_window_is_rejected(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');

        // Raise the permitted runtime past the reservation window.
        config()->set(
            'sender.deployment_limits.max_worker_runtime_seconds',
            $retryAfter + 60,
        );

        $check = $this->reservationCheck();

        $this->assertSame(CapabilityStatus::Unavailable, $check->capability);
        $this->assertStringContainsString('second worker', implode(' ', $check->remedies));
    }

    public function test_the_margin_is_part_of_the_requirement_not_optional(): void
    {
        // Exactly the worker runtime, with no margin, is not sufficient.
        config()->set(
            'sender.deployment_limits.max_worker_runtime_seconds',
            240,
        );
        config()->set('queue.connections.database.retry_after', 240);

        $this->assertSame(CapabilityStatus::Unavailable, $this->reservationCheck()->capability);

        // One second of margin is enough.
        config()->set('queue.connections.database.retry_after', 241);

        $this->assertSame(CapabilityStatus::Unavailable, $this->reservationCheck()->capability);
    }

    public function test_the_requirement_is_derived_from_the_deployment_limit(): void
    {
        $runtime = DeploymentLimit::MaxWorkerRuntimeSeconds->value();
        $margin = (int) config('sender.capabilities.queue.reservation_margin_seconds');
        $retryAfter = (int) config('queue.connections.database.retry_after');

        $this->assertGreaterThan(
            $runtime,
            $retryAfter,
            'the shipped retry_after must exceed the permitted worker runtime',
        );
        $this->assertGreaterThanOrEqual($runtime + $margin, $retryAfter);
    }

    public function test_violating_the_invariant_makes_the_queue_capability_unavailable(): void
    {
        config()->set('queue.connections.database.retry_after', 30);

        config()->set('queue.default', 'database');

        $this->app->forgetInstance(CapabilityRegistry::class);

        // The driver itself is still perfectly valid, and is reported as such.
        $driver = array_values(array_filter(
            app(HostCapabilityInspector::class)->inspect()->checks,
            static fn ($check): bool => $check->name === 'queue driver',
        ))[0];

        $this->assertSame(CapabilityStatus::Ready, $driver->capability);

        // The capability as a whole is not usable, which is the point.
        $this->assertSame(
            CapabilityStatus::Unavailable,
            app(CapabilityRegistry::class)->status(CapabilitySubject::Queue),
        );
    }

    public function test_the_check_is_skipped_when_no_reservation_window_can_exist(): void
    {
        config()->set('queue.default', 'sync');

        $names = array_map(
            static fn ($check): string => $check->name,
            app(HostCapabilityInspector::class)->inspect()->checks,
        );

        $this->assertNotContains('queue reservation window', $names);
    }

    /**
     * The documented variable and the consumed variable had drifted apart:
     * `.env.example` told operators to set `QUEUE_RETRY_AFTER` while
     * `config/queue.php` read `DB_QUEUE_RETRY_AFTER`. An operator following the
     * documentation changed nothing and would never have known why.
     */
    public function test_the_documented_environment_variable_is_the_one_actually_read(): void
    {
        $source = (string) file_get_contents(config_path('queue.php'));

        $this->assertStringContainsString(
            "env('DB_QUEUE_RETRY_AFTER'",
            $source,
            'config/queue.php must read the database connection reservation window',
        );

        $documented = (string) file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString(
            'DB_QUEUE_RETRY_AFTER',
            $documented,
            '.env.example must document the variable the configuration reads',
        );

        // The unprefixed name must not survive anywhere in the documentation,
        // because that is the one an operator would copy into .env.
        foreach (glob(base_path('docs/*.md')) ?: [] as $file) {
            $text = (string) file_get_contents($file);

            $this->assertStringNotContainsStringIgnoringCase(
                'QUEUE_RETRY_AFTER',
                str_replace('DB_QUEUE_RETRY_AFTER', '', $text),
                basename($file).' documents a retry_after variable that config/queue.php does not read',
            );
        }
    }

    /**
     * The extraction request ceiling is only meaningful relative to the worker
     * budget: seeding more URLs than the runtime can fetch produces a timeout
     * and a partial result instead of an honest failure.
     */
    public function test_a_seed_batch_can_actually_finish_inside_the_worker_runtime(): void
    {
        $urls = DeploymentLimit::MaxUrlsPerRequest->value();
        $runtime = DeploymentLimit::MaxWorkerRuntimeSeconds->value();

        // Two seconds per fetch is pessimistic for a page fetch that has already
        // paid for DNS and TLS, and still has to leave time for the request to
        // finish rather than being killed mid-batch.
        $this->assertLessThanOrEqual(
            intdiv($runtime, 2),
            $urls,
            'the shipped seed ceiling cannot finish inside the worker runtime',
        );
    }

    public function test_pasted_text_ceiling_is_conservative_for_shared_hosting(): void
    {
        $bytes = DeploymentLimit::MaxTextInputBytes->value();

        $this->assertGreaterThan(0, $bytes);
        $this->assertLessThanOrEqual(
            1024 * 1024,
            $bytes,
            'pasted text ceiling should stay at or below 1 MiB on shared hosting',
        );
    }
}

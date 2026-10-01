<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\System\Capabilities\CapabilityRegistry;
use App\Domain\System\CapabilityCheck;
use App\Domain\System\Contracts\HostInspector;
use App\Domain\System\Enums\CapabilityStatus;
use App\Domain\System\Enums\CapabilitySubject;
use App\Domain\System\HostCapabilityReport;
use App\Domain\System\Mail\SmtpCapability;
use App\Domain\System\Runs\RunObserver;
use Tests\TestCase;

/**
 * Diagnostic severity and process exit status are different things. A degraded
 * host is operational, and a command that fails on it teaches operators to
 * ignore it.
 */
class DiagnoseCommandTest extends TestCase
{
    public function test_a_degraded_installation_still_exits_successfully(): void
    {
        config()->set('sender.capabilities.required', []);

        $this->bindInspector([
            CapabilityCheck::ready('PHP runtime', PHP_VERSION),
            CapabilityCheck::ready('storage', '', [], CapabilitySubject::Storage),
            CapabilityCheck::degraded('memory_limit', '8M (needs 256M)'),
        ]);

        $this->assertSame(CapabilityStatus::Degraded, $this->registry()->overall());

        $this->artisan('sender:diagnose')->assertSuccessful();
    }

    public function test_a_healthy_installation_exits_successfully(): void
    {
        config()->set('sender.capabilities.required', []);

        $this->bindInspector([
            CapabilityCheck::ready('PHP runtime', PHP_VERSION),
            CapabilityCheck::ready('storage', '', [], CapabilitySubject::Storage),
        ]);
        $this->recordRun();

        $this->assertSame(CapabilityStatus::Ready, $this->registry()->overall());

        $this->artisan('sender:diagnose')->assertSuccessful();
    }

    public function test_an_unavailable_capability_fails_the_command(): void
    {
        config()->set('sender.capabilities.required', []);

        $this->bindInspector([
            CapabilityCheck::unavailable('ext: pdo_mysql', 'not loaded'),
        ]);

        $this->assertSame(CapabilityStatus::Unavailable, $this->registry()->overall());

        $this->artisan('sender:diagnose')->assertFailed();
    }

    public function test_an_unestablished_required_capability_fails_the_command(): void
    {
        config()->set('sender.capabilities.required', [CapabilitySubject::Smtp->value]);

        $this->assertSame(CapabilityStatus::Unknown, $this->registry()->overall());

        $this->artisan('sender:diagnose')->assertFailed();
    }

    public function test_an_unestablished_optional_capability_does_not_fail_the_command(): void
    {
        config()->set('sender.capabilities.required', []);

        $this->assertSame(CapabilityStatus::Unknown, $this->registry()->status(CapabilitySubject::Smtp));
        $this->assertNotSame(CapabilityStatus::Unknown, $this->registry()->overall());

        $this->artisan('sender:diagnose')->assertSuccessful();
    }

    /**
     * @param  list<CapabilityCheck>  $checks
     */
    private function bindInspector(array $checks): void
    {
        $report = HostCapabilityReport::fromChecks($checks);

        $this->app->singleton(
            HostInspector::class,
            static fn (): HostInspector => new class($report) implements HostInspector
            {
                public function __construct(private readonly HostCapabilityReport $report) {}

                public function inspect(): HostCapabilityReport
                {
                    return $this->report;
                }
            },
        );

        $this->app->singleton(
            CapabilityRegistry::class,
            fn ($app) => new CapabilityRegistry(
                $app->make(HostInspector::class),
                $app->make(RunObserver::class),
                $app->make(SmtpCapability::class),
            ),
        );
    }

    private function registry(): CapabilityRegistry
    {
        return $this->app->make(CapabilityRegistry::class);
    }
}

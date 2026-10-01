<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\System\Enums\Capability;
use App\Domain\System\HostCapabilityReport;
use App\Domain\System\HostEnvironment;
use App\Domain\System\Services\HostCapabilityInspector;
use Tests\TestCase;

/**
 * The CLI SAPI reports different runtime limits than the web SAPI, so the
 * inspector is exercised against a fabricated snapshot to cover the paths a
 * hosted PHP process actually takes.
 */
class HostCapabilityInspectorTest extends TestCase
{
    /**
     * @param  array<string, string>  $overrides
     */
    private function environment(array $overrides = []): HostEnvironment
    {
        return new HostEnvironment(
            phpVersion: $overrides['phpVersion'] ?? '8.4.0',
            memoryLimit: $overrides['memoryLimit'] ?? '256M',
            maxExecutionTimeSeconds: (int) ($overrides['maxExecutionTime'] ?? 60),
            maxUploadBytes: (int) ($overrides['maxUpload'] ?? 20 * 1024 * 1024),
            maxPostBytes: (int) ($overrides['maxPost'] ?? 20 * 1024 * 1024),
        );
    }

    public function test_a_comfortable_web_host_is_ready(): void
    {
        $report = (new HostCapabilityInspector($this->environment()))->inspect();

        $this->assertSame('READY', $report->asMap()['max_execution_time'] ?? null);
        $this->assertSame('READY', $report->asMap()['upload_max_filesize'] ?? null);
        $this->assertSame('READY', $report->asMap()['memory_limit'] ?? null);
    }

    public function test_an_unlimited_execution_time_is_ready(): void
    {
        $report = (new HostCapabilityInspector($this->environment(['maxExecutionTime' => 0])))->inspect();

        $this->assertSame('READY', $report->asMap()['max_execution_time']);
        $this->assertStringContainsString('unlimited', $this->detail($report, 'max_execution_time'));
    }

    public function test_a_short_execution_time_is_degraded_and_never_unavailable(): void
    {
        $report = (new HostCapabilityInspector($this->environment(['maxExecutionTime' => 30])))->inspect();

        $this->assertSame('DEGRADED', $report->asMap()['max_execution_time']);
        $this->assertStringContainsString('30s', $this->detail($report, 'max_execution_time'));
        $this->assertStringContainsString('60s', $this->detail($report, 'max_execution_time'));
    }

    public function test_an_undersized_upload_limit_is_reported_against_the_configured_limit(): void
    {
        $report = (new HostCapabilityInspector($this->environment([
            'maxUpload' => 2 * 1024 * 1024,
            'maxPost' => 2 * 1024 * 1024,
        ])))->inspect();

        $this->assertSame('DEGRADED', $report->asMap()['upload_max_filesize']);
        $this->assertStringContainsString('2M', $this->detail($report, 'upload_max_filesize'));
        $this->assertSame('DEGRADED', $report->asMap()['post_max_size']);
    }

    public function test_an_unsupported_php_version_is_unavailable(): void
    {
        $report = (new HostCapabilityInspector($this->environment(['phpVersion' => '8.1.30'])))->inspect();

        $this->assertSame('UNAVAILABLE', $report->asMap()['PHP runtime']);
        $this->assertFalse($report->passes);
        $this->assertNotSame(Capability::Ready, $report->overall);
    }

    public function test_an_unlimited_memory_limit_is_flagged_as_degraded(): void
    {
        $report = (new HostCapabilityInspector($this->environment(['memoryLimit' => '-1'])))->inspect();

        $this->assertSame('DEGRADED', $report->asMap()['memory_limit']);
    }

    private function detail(HostCapabilityReport $report, string $name): string
    {
        foreach ($report->checks as $check) {
            if ($check->name === $name) {
                return $check->detail;
            }
        }

        $this->fail("No check named '{$name}' was produced.");
    }
}

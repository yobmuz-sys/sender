<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\System\CapabilityCheck;
use App\Domain\System\Enums\Capability;
use App\Domain\System\HostCapabilityReport;
use PHPUnit\Framework\TestCase;

class HostCapabilityReportTest extends TestCase
{
    public function test_an_all_ready_report_passes(): void
    {
        $report = HostCapabilityReport::fromChecks([
            CapabilityCheck::ready('php'),
            CapabilityCheck::ready('database'),
        ]);

        $this->assertTrue($report->passes);
        $this->assertSame(Capability::Ready, $report->overall);
        $this->assertSame([], $report->problems());
    }

    public function test_one_unavailable_check_fails_the_whole_report(): void
    {
        $report = HostCapabilityReport::fromChecks([
            CapabilityCheck::ready('php'),
            CapabilityCheck::degraded('memory_limit'),
            CapabilityCheck::unavailable('database'),
        ]);

        $this->assertFalse($report->passes);
        $this->assertSame(Capability::Unavailable, $report->overall);
    }

    public function test_degraded_only_is_reported_as_degraded(): void
    {
        $report = HostCapabilityReport::fromChecks([
            CapabilityCheck::ready('php'),
            CapabilityCheck::degraded('memory_limit'),
        ]);

        $this->assertFalse($report->passes);
        $this->assertSame(Capability::Degraded, $report->overall);
        $this->assertCount(1, $report->problems());
    }

    public function test_capability_merge_keeps_the_worst_status(): void
    {
        $this->assertSame(Capability::Degraded, Capability::Ready->merge(Capability::Degraded));
        $this->assertSame(Capability::Degraded, Capability::Degraded->merge(Capability::Ready));
        $this->assertSame(Capability::Unavailable, Capability::Degraded->merge(Capability::Unavailable));
        $this->assertSame(Capability::Ready, Capability::Ready->merge(Capability::Ready));
    }
}

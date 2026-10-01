<?php

declare(strict_types=1);

namespace App\Domain\System\Capabilities;

use App\Domain\System\CapabilityCheck;
use App\Domain\System\Contracts\HostInspector;
use App\Domain\System\Enums\CapabilityStatus;
use App\Domain\System\Enums\CapabilitySubject;
use App\Domain\System\HostCapabilityReport;
use App\Domain\System\Services\CronHeartbeat;

/**
 * The application-facing view of what this installation can do.
 *
 * Feature code asks this registry and never reaches for extension_loaded(),
 * ini_get() or disk_free_space() directly. Those stay in the {@see HostInspector}
 * implementation, which measures reality; the registry decides what the rest of
 * the application is allowed to rely on.
 *
 * The distinction matters for a capability nobody has measured yet. Such a
 * capability is UNKNOWN, never READY. An unverified dependency is not a
 * working dependency.
 */
final class CapabilityRegistry
{
    private ?HostCapabilityReport $report = null;

    public function __construct(
        private readonly HostInspector $inspector,
        private readonly CronHeartbeat $cron,
    ) {}

    /**
     * The raw host measurement, including the cron observation.
     */
    public function report(): HostCapabilityReport
    {
        return $this->report ??= HostCapabilityReport::fromChecks([
            ...$this->inspector->inspect()->checks,
            $this->cron->check(),
        ]);
    }

    /**
     * The measured state of one capability.
     *
     * A subject with no measurement is UNKNOWN. That is the honest answer and
     * it is not silently upgraded to READY.
     */
    public function status(CapabilitySubject $subject): CapabilityStatus
    {
        // Accumulate only the checks that are evidence for this subject. The
        // fallback has to be UNKNOWN rather than the starting value, otherwise
        // a subject with only READY evidence would stay UNKNOWN because
        // UNKNOWN outranks READY when merging.
        $status = null;

        foreach ($this->report()->checks as $check) {
            if ($check->subject !== $subject) {
                continue;
            }

            $status = $status === null
                ? $check->capability
                : $status->merge($check->capability);
        }

        return $status ?? CapabilityStatus::Unknown;
    }

    /**
     * Whether the capability is measured and confirmed usable.
     *
     * DEGRADED is not available: it means working with reduced capacity, and
     * an operation that needs full capacity must say so through a deployment
     * limit rather than by being told the host is fine.
     */
    public function isAvailable(CapabilitySubject $subject): bool
    {
        return $this->status($subject)->isUsable();
    }

    /**
     * @return array<string, CapabilityStatus>
     */
    public function statuses(): array
    {
        $statuses = [];

        foreach (CapabilitySubject::cases() as $subject) {
            $statuses[$subject->value] = $this->status($subject);
        }

        return $statuses;
    }

    /**
     * The installation's verdict.
     *
     * Host-level checks always count. Subject-level checks count only when the
     * subject is required, so an UNKNOWN capability nothing depends on is
     * reported rather than failing the whole installation.
     */
    public function overall(): CapabilityStatus
    {
        $overall = CapabilityStatus::Ready;

        foreach ($this->report()->checks as $check) {
            if ($check->subject === null) {
                $overall = $overall->merge($check->capability);
            }
        }

        foreach (CapabilitySubject::cases() as $subject) {
            $status = $this->status($subject);

            if ($status === CapabilityStatus::Unknown && ! $subject->isRequired()) {
                continue;
            }

            $overall = $overall->merge($status);
        }

        return $overall;
    }

    /**
     * @return list<CapabilityCheck>
     */
    public function problems(): array
    {
        return $this->report()->problems();
    }
}

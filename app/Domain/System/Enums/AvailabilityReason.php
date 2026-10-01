<?php

declare(strict_types=1);

namespace App\Domain\System\Enums;

/**
 * Why an operation is blocked.
 *
 * Present only when {@see AvailabilityState} is BLOCKED. The reason is
 * deliberately machine-readable so an API response, a UI message and a log
 * line can all derive from the same value without re-deriving the logic.
 */
enum AvailabilityReason: string
{
    case CapabilityUnavailable = 'CAPABILITY_UNAVAILABLE';
    case SubsystemDisabled = 'SUBSYSTEM_DISABLED';
    case NotEntitled = 'NOT_ENTITLED';

    public function label(): string
    {
        return match ($this) {
            self::CapabilityUnavailable => 'This installation cannot perform this operation.',
            self::SubsystemDisabled => 'This subsystem has been disabled by the operator.',
            self::NotEntitled => 'Your account is not entitled to this feature.',
        };
    }
}

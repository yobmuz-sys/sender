<?php

declare(strict_types=1);

namespace App\Domain\System\Enums;

/**
 * Whether an operation may currently proceed.
 *
 *   AVAILABLE   verified capable, enabled and entitled
 *   UNVERIFIED  not blocked, but a dependency has not been established
 *   BLOCKED     something positively prevents the operation
 *
 * UNVERIFIED exists because an unmeasured dependency is not automatically a
 * failure. Whether an operation may proceed anyway is a decision for the
 * consuming requirement, not a consequence of the capability registry.
 */
enum AvailabilityState: string
{
    case Available = 'AVAILABLE';
    case Unverified = 'UNVERIFIED';
    case Blocked = 'BLOCKED';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Unverified => 'Unverified',
            self::Blocked => 'Blocked',
        };
    }
}

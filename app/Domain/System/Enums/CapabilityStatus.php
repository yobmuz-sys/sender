<?php

declare(strict_types=1);

namespace App\Domain\System\Enums;

/**
 * The state of a capability, as established by measurement.
 *
 * UNKNOWN is deliberately distinct from UNAVAILABLE:
 *
 *   UNKNOWN     the platform has not established whether this works
 *   DEGRADED    it works, but with less headroom than the platform prefers
 *   UNAVAILABLE it was tested and is known not to work
 *
 * Collapsing "not checked" into either of the other two is how a system starts
 * reporting untested infrastructure as working.
 */
enum CapabilityStatus: string
{
    case Ready = 'READY';
    case Degraded = 'DEGRADED';
    case Unknown = 'UNKNOWN';
    case Unavailable = 'UNAVAILABLE';

    /**
     * Ordering used when collapsing many statuses into one verdict.
     *
     * UNKNOWN outranks DEGRADED because a known constraint is safer to plan
     * around than an unverified one.
     */
    private function rank(): int
    {
        return match ($this) {
            self::Ready => 0,
            self::Degraded => 1,
            self::Unknown => 2,
            self::Unavailable => 3,
        };
    }

    /**
     * The more pessimistic of two statuses wins.
     */
    public function merge(self $other): self
    {
        return $this->rank() >= $other->rank() ? $this : $other;
    }

    /**
     * Whether the capability was measured and found usable.
     *
     * UNKNOWN is not usable: an operation must not assume an unverified
     * dependency will behave.
     */
    public function isUsable(): bool
    {
        return $this === self::Ready;
    }

    /**
     * Whether the capability is known not to work.
     */
    public function isUnavailable(): bool
    {
        return $this === self::Unavailable;
    }

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Ready',
            self::Degraded => 'Degraded',
            self::Unknown => 'Unknown',
            self::Unavailable => 'Unavailable',
        };
    }
}

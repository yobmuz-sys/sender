<?php

declare(strict_types=1);

namespace App\Domain\System\Enums;

/**
 * The three states a host capability can be reported in.
 *
 * The inspector must never present a partially working dependency as working,
 * so "present but not sufficient" resolves to Degraded rather than Ready.
 */
enum Capability: string
{
    case Ready = 'READY';
    case Degraded = 'DEGRADED';
    case Unavailable = 'UNAVAILABLE';

    /**
     * The worst status wins when a check reports several sub-conditions.
     */
    public function merge(self $other): self
    {
        return match (true) {
            $this === self::Unavailable || $other === self::Unavailable => self::Unavailable,
            $this === self::Degraded || $other === self::Degraded => self::Degraded,
            default => self::Ready,
        };
    }

    /**
     * Whether the platform can rely on this capability.
     */
    public function isUsable(): bool
    {
        return $this !== self::Unavailable;
    }

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Ready',
            self::Degraded => 'Degraded',
            self::Unavailable => 'Unavailable',
        };
    }
}

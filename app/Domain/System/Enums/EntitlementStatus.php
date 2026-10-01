<?php

declare(strict_types=1);

namespace App\Domain\System\Enums;

/**
 * Whether an account is permitted to use a feature.
 *
 * This is an authorization/commercial fact about the account, and is
 * deliberately NOT part of {@see CapabilityStatus}. An installation that is
 * fully capable but the account is not entitled is a different situation from
 * a host that cannot perform the work at all, and conflating them produces
 * misleading diagnostics and misleading error messages.
 */
enum EntitlementStatus: string
{
    case Entitled = 'ENTITLED';
    case NotEntitled = 'NOT_ENTITLED';

    public function label(): string
    {
        return match ($this) {
            self::Entitled => 'Entitled',
            self::NotEntitled => 'Not entitled',
        };
    }
}

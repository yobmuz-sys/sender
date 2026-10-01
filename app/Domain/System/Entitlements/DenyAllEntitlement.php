<?php

declare(strict_types=1);

namespace App\Domain\System\Entitlements;

use App\Domain\System\Enums\EntitlementStatus;

/**
 * Denies every feature.
 *
 * This is the bound until plans exist, and it is the safe bound: an
 * unentitled feature must fail closed rather than open. When the plans stage
 * arrives it replaces this binding, and nothing that depends on the
 * {@see Entitlement} interface has to change.
 */
final class DenyAllEntitlement implements Entitlement
{
    public function status(string $feature): EntitlementStatus
    {
        return EntitlementStatus::NotEntitled;
    }

    public function allows(string $feature): bool
    {
        return false;
    }
}

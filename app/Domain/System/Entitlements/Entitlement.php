<?php

declare(strict_types=1);

namespace App\Domain\System\Entitlements;

use App\Domain\System\Enums\EntitlementStatus;

/**
 * Whether an account is granted a feature.
 *
 * This is the seam that later stages fill with plans, overrides and
 * temporary grants. Application services depend on this interface rather than
 * on plan logic, so no tool ever reads a plan directly and no billing concept
 * leaks into a feature.
 *
 * It is intentionally NOT an extension of CapabilityStatus: infrastructure
 * availability and commercial authorization are different facts about
 * different things.
 */
interface Entitlement
{
    public function status(string $feature): EntitlementStatus;

    public function allows(string $feature): bool;
}

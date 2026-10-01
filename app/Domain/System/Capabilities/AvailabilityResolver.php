<?php

declare(strict_types=1);

namespace App\Domain\System\Capabilities;

use App\Domain\System\Entitlements\Entitlement;
use App\Domain\System\Enums\AvailabilityReason;
use App\Domain\System\Enums\AvailabilityState;
use App\Domain\System\Enums\CapabilityStatus;
use App\Domain\System\Enums\EntitlementStatus;
use App\Domain\System\Enums\Subsystem;
use App\Domain\System\Flags\SubsystemFlagRegistry;

/**
 * Composes the three independent inputs into one availability decision.
 *
 * The order matters and is deliberate:
 *
 *   1. infrastructure  can this installation physically do it?
 *   2. operator        has the subsystem been enabled?
 *   3. account         is the account entitled?
 *
 * A required capability that is UNKNOWN yields UNVERIFIED rather than BLOCKED.
 * The registry reports what it does not know; it does not get to decide that an
 * unmeasured dependency is a fatal one. Only the consuming requirement can do
 * that, and doing it here would make every future feature inherit a policy
 * nobody chose.
 */
final class AvailabilityResolver
{
    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly SubsystemFlagRegistry $flags,
        private readonly Entitlement $entitlement,
    ) {}

    /**
     * @param  string|null  $feature  Entitlement key; defaults to the subsystem name.
     */
    public function resolve(Subsystem $subsystem, ?string $feature = null): Availability
    {
        $feature ??= $subsystem->value;

        $capability = $this->capabilities->status($subsystem->subject());
        $enabled = $this->flags->enabled($subsystem);
        $entitlement = $this->entitlement->status($feature);

        if ($capability->isUnavailable()) {
            return new Availability(
                AvailabilityState::Blocked,
                AvailabilityReason::CapabilityUnavailable,
                $capability,
                $entitlement,
                $enabled,
                $subsystem->label().' cannot be used on this installation.',
            );
        }

        if (! $enabled) {
            return new Availability(
                AvailabilityState::Blocked,
                AvailabilityReason::SubsystemDisabled,
                $capability,
                $entitlement,
                $enabled,
                AvailabilityReason::SubsystemDisabled->label(),
            );
        }

        if ($entitlement === EntitlementStatus::NotEntitled) {
            return new Availability(
                AvailabilityState::Blocked,
                AvailabilityReason::NotEntitled,
                $capability,
                $entitlement,
                $enabled,
                AvailabilityReason::NotEntitled->label(),
            );
        }

        if ($capability === CapabilityStatus::Unknown) {
            return new Availability(
                AvailabilityState::Unverified,
                null,
                $capability,
                $entitlement,
                $enabled,
                'This installation has not established whether '.$subsystem->label().' works.',
            );
        }

        return new Availability(
            AvailabilityState::Available,
            null,
            $capability,
            $entitlement,
            $enabled,
            $subsystem->label().' is available.',
        );
    }

    /**
     * @return list<Availability>
     */
    public function resolveAll(): array
    {
        return array_map(
            fn (Subsystem $subsystem): Availability => $this->resolve($subsystem),
            Subsystem::cases(),
        );
    }
}

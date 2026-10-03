<?php

declare(strict_types=1);

namespace App\Domain\System\Capabilities;

use App\Domain\System\Enums\AvailabilityReason;
use App\Domain\System\Enums\AvailabilityState;
use App\Domain\System\Enums\CapabilityStatus;
use App\Domain\System\Enums\EntitlementStatus;

/**
 * The answer to "may this operation proceed right now?", together with why.
 *
 * This is vocabulary, not an execution engine. It exists so that the decision
 * is composed in one place rather than re-derived at every call site, where it
 * would inevitably drift.
 *
 * `capability` is null for a subsystem that has no measurable host dependency.
 * A consumer must not read that as a weak READY: there is no evidence either
 * way, and a null capability is a question this installation never asked.
 */
final readonly class Availability
{
    public function __construct(
        public AvailabilityState $state,
        public ?AvailabilityReason $reason,
        public ?CapabilityStatus $capability,
        public EntitlementStatus $entitlement,
        public bool $subsystemEnabled,
        public string $explanation = '',
    ) {}

    public function allowed(): bool
    {
        return $this->state !== AvailabilityState::Blocked;
    }

    /**
     * Whether the operation may proceed but a dependency is unverified.
     */
    public function unverified(): bool
    {
        return $this->state === AvailabilityState::Unverified;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'reason' => $this->reason?->value,
            'capability' => $this->capability?->value,
            'entitlement' => $this->entitlement->value,
            'subsystem_enabled' => $this->subsystemEnabled,
            'explanation' => $this->explanation,
        ];
    }
}

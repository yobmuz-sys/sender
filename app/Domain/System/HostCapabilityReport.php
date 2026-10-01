<?php

declare(strict_types=1);

namespace App\Domain\System;

use App\Domain\System\Capabilities\CapabilityRegistry;
use App\Domain\System\Enums\CapabilityStatus;

/**
 * The aggregated result of one host inspection.
 *
 * This is the *measurement* layer: what is true about this machine. It is not
 * what the application should consume — {@see CapabilityRegistry}
 * answers that, by combining these measurements with capability subjects.
 *
 * The overall capability is the worst individual check: a host missing a single
 * required dependency is never reported as fully ready.
 */
final readonly class HostCapabilityReport
{
    /**
     * @param  list<CapabilityCheck>  $checks
     */
    public function __construct(
        public array $checks,
        public CapabilityStatus $overall,
        public bool $passes,
    ) {}

    /**
     * @param  list<CapabilityCheck>  $checks
     */
    public static function fromChecks(array $checks): self
    {
        $overall = CapabilityStatus::Ready;

        foreach ($checks as $check) {
            $overall = $overall->merge($check->capability);
        }

        return new self($checks, $overall, $overall === CapabilityStatus::Ready);
    }

    /**
     * Checks that did not come back READY.
     *
     * @return list<CapabilityCheck>
     */
    public function problems(): array
    {
        return array_values(array_filter(
            $this->checks,
            static fn (CapabilityCheck $check): bool => $check->capability !== CapabilityStatus::Ready,
        ));
    }

    /**
     * @return array<string, string>
     */
    public function asMap(): array
    {
        $map = [];

        foreach ($this->checks as $check) {
            $map[$check->name] = $check->capability->value;
        }

        return $map;
    }

    /**
     * @return array{capability: string, passes: bool, checks: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'capability' => $this->overall->value,
            'passes' => $this->passes,
            'checks' => array_map(
                static fn (CapabilityCheck $check): array => $check->toArray(),
                $this->checks,
            ),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\System;

use App\Domain\System\Enums\Capability;

/**
 * The aggregated result of one host inspection.
 *
 * The overall capability is the worst individual check: a host missing a single
 * required dependency is never reported as ready for the whole platform.
 */
final readonly class HostCapabilityReport
{
    /**
     * @param  list<CapabilityCheck>  $checks
     */
    public function __construct(
        public array $checks,
        public Capability $overall,
        public bool $passes,
    ) {}

    /**
     * @param  list<CapabilityCheck>  $checks
     */
    public static function fromChecks(array $checks): self
    {
        $overall = Capability::Ready;

        foreach ($checks as $check) {
            $overall = $overall->merge($check->capability);
        }

        return new self($checks, $overall, $overall === Capability::Ready);
    }

    /**
     * @return list<CapabilityCheck>
     */
    public function problems(): array
    {
        return array_values(array_filter(
            $this->checks,
            static fn (CapabilityCheck $check): bool => $check->capability !== Capability::Ready,
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

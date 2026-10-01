<?php

declare(strict_types=1);

namespace App\Domain\System;

use App\Domain\System\Enums\Capability;

/**
 * A single inspected host capability.
 *
 * `detail` carries the measured value (for example "8.4.24" or "512M") so the
 * inspector stays the only place that reads raw PHP runtime state.
 */
final readonly class CapabilityCheck
{
    /**
     * @param  list<string>  $remedies  Human readable steps to resolve an unavailable check.
     */
    public function __construct(
        public string $name,
        public Capability $capability,
        public string $detail = '',
        public array $remedies = [],
    ) {}

    /**
     * @param  list<string>  $remedies
     */
    public static function ready(string $name, string $detail = '', array $remedies = []): self
    {
        return new self($name, Capability::Ready, $detail, $remedies);
    }

    /**
     * @param  list<string>  $remedies
     */
    public static function degraded(string $name, string $detail = '', array $remedies = []): self
    {
        return new self($name, Capability::Degraded, $detail, $remedies);
    }

    /**
     * @param  list<string>  $remedies
     */
    public static function unavailable(string $name, string $detail = '', array $remedies = []): self
    {
        return new self($name, Capability::Unavailable, $detail, $remedies);
    }

    /**
     * @return array{name: string, capability: string, detail: string, remedies: list<string>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'capability' => $this->capability->value,
            'detail' => $this->detail,
            'remedies' => $this->remedies,
        ];
    }
}

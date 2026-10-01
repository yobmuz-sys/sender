<?php

declare(strict_types=1);

namespace App\Domain\System;

use App\Domain\System\Enums\CapabilityStatus;
use App\Domain\System\Enums\CapabilitySubject;

/**
 * A single inspected capability.
 *
 * `detail` carries the measured value (for example "8.4.24" or "512M") so the
 * inspector stays the only place that reads raw PHP runtime state.
 *
 * `subject` links a check to a {@see CapabilitySubject}. Checks with a null
 * subject describe the host as a whole rather than one capability; they still
 * count towards the overall verdict but do not answer a subject query.
 */
final readonly class CapabilityCheck
{
    /**
     * @param  list<string>  $remedies  Human readable steps to resolve an unavailable check.
     */
    public function __construct(
        public string $name,
        public CapabilityStatus $capability,
        public string $detail = '',
        public array $remedies = [],
        public ?CapabilitySubject $subject = null,
    ) {}

    /**
     * @param  list<string>  $remedies
     */
    public static function ready(string $name, string $detail = '', array $remedies = [], ?CapabilitySubject $subject = null): self
    {
        return new self($name, CapabilityStatus::Ready, $detail, $remedies, $subject);
    }

    /**
     * @param  list<string>  $remedies
     */
    public static function degraded(string $name, string $detail = '', array $remedies = [], ?CapabilitySubject $subject = null): self
    {
        return new self($name, CapabilityStatus::Degraded, $detail, $remedies, $subject);
    }

    /**
     * @param  list<string>  $remedies
     */
    public static function unavailable(string $name, string $detail = '', array $remedies = [], ?CapabilitySubject $subject = null): self
    {
        return new self($name, CapabilityStatus::Unavailable, $detail, $remedies, $subject);
    }

    /**
     * Used when nothing has established the capability yet.
     *
     * @param  list<string>  $remedies
     */
    public static function unknown(string $name, string $detail = '', array $remedies = [], ?CapabilitySubject $subject = null): self
    {
        return new self($name, CapabilityStatus::Unknown, $detail, $remedies, $subject);
    }

    /**
     * @return array{name: string, capability: string, subject: string|null, detail: string, remedies: list<string>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'capability' => $this->capability->value,
            'subject' => $this->subject?->value,
            'detail' => $this->detail,
            'remedies' => $this->remedies,
        ];
    }
}

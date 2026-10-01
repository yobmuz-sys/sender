<?php

declare(strict_types=1);

namespace App\Domain\System\Network;

use App\Domain\System\Enums\CapabilityStatus;
use App\Domain\System\Mail\SmtpVerification;

/**
 * The outcome of one attempt to establish that URL fetching works.
 *
 * Mirrors {@see SmtpVerification} deliberately, so the
 * two capability checks read the same way in the admin surface and are stored
 * the same way. Consistency here is worth more than novelty: an operator
 * diagnosing "why is this subsystem unavailable" should not have to learn two
 * different result shapes.
 *
 * `summary` and stage details are written to be shown. Anything that would
 * identify the internal network — a resolved address, a refused port, a proxy
 * detail — belongs in the operator's own logs, not here, where it outlives the
 * deployment that produced it.
 */
final class UrlVerification
{
    public const STAGE_CONNECTIVITY = 'connectivity';

    public const STAGE_POLICY = 'policy';

    public const STAGE_CONTENT = 'content';

    /**
     * @param  list<array{name: string, passed: bool, detail: string}>  $stages
     */
    public function __construct(
        public CapabilityStatus $status,
        public array $stages,
        public string $summary,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'stages' => $this->stages,
            'summary' => $this->summary,
            'verified_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): self
    {
        $stages = [];

        foreach ((array) ($stored['stages'] ?? []) as $entry) {
            if (! is_array($entry) || ! isset($entry['name'])) {
                continue;
            }

            $stages[] = [
                'name' => (string) $entry['name'],
                'passed' => (bool) ($entry['passed'] ?? false),
                'detail' => (string) ($entry['detail'] ?? ''),
            ];
        }

        $status = CapabilityStatus::tryFrom((string) ($stored['status'] ?? ''))
            ?? CapabilityStatus::Unknown;

        return new self($status, $stages, (string) ($stored['summary'] ?? ''));
    }

    public function proved(string $stage): bool
    {
        foreach ($this->stages as $entry) {
            if ($entry['name'] === $stage) {
                return $entry['passed'];
            }
        }

        return false;
    }
}

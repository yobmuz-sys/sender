<?php

declare(strict_types=1);

namespace App\Domain\Audience;

/**
 * One address, checked, with the evidence that produced the verdict.
 *
 * The outcome alone is not enough to cache or to explain. The domain result and
 * the catch-all verdict are kept alongside it because both are properties of the
 * domain rather than of the address, and because a report that shows "unknown —
 * the domain accepts any address" must be able to say *why* rather than
 * asserting a conclusion.
 */
final readonly class ValidationResult
{
    public function __construct(
        public string $email,
        public string $domain,
        public ValidationOutcome $outcome,
        public ?MailRoute $mailRoute = null,
        public ?CatchAllVerdict $catchAll = null,
    ) {}

    public function status(): ValidationStatus
    {
        return $this->outcome->status;
    }

    /**
     * The technical explanation shown under "Why?".
     *
     * @return array<string, mixed>
     */
    public function technicalDetail(): array
    {
        return [
            'outcome' => $this->outcome->technicalDetail(),
            'domain' => $this->mailRoute?->technicalDetail(),
            'catch_all' => $this->catchAll?->value,
        ];
    }
}

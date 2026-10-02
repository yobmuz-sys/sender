<?php

declare(strict_types=1);

namespace App\Domain\Audience;

use DateTimeInterface;

/**
 * One domain's mail-routing evidence, and when it was observed.
 *
 * Carries the status, the MX targets that were found, and the moment of the
 * lookup. The targets are kept because a report that says "accepts email" without
 * saying where to should be regarded as an unfalsifiable claim, and an operator
 * asking "why is this one different" needs them.
 *
 * `observedAt` is what makes the result cacheable and what stops a stale answer
 * being presented as fresh.
 */
final readonly class MailRoute
{
    /**
     * @param  list<string>  $targets  MX hosts, or the resolved A/AAAA addresses
     *                                 when the domain relies on the implicit route.
     */
    public function __construct(
        public string $domain,
        public MailRouteStatus $status,
        public array $targets,
        public DateTimeInterface $observedAt,
    ) {}

    /**
     * @param  list<string>  $targets
     */
    public static function of(string $domain, MailRouteStatus $status, array $targets, DateTimeInterface $at): self
    {
        return new self(strtolower($domain), $status, array_values($targets), $at);
    }

    /**
     * Whether a mailbox check should be attempted at this domain.
     */
    public function permitsMailboxCheck(): bool
    {
        return $this->status->permitsMailboxCheck();
    }

    /**
     * The technical summary shown under "Why?".
     *
     * @return array{status: string, targets: list<string>, checked_at: string}
     */
    public function technicalDetail(): array
    {
        return [
            'status' => $this->status->value,
            'targets' => $this->targets,
            'checked_at' => $this->observedAt->format(DATE_ATOM),
        ];
    }
}

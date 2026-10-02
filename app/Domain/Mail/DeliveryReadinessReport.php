<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * The observable facts about one account, and what they currently permit.
 *
 * The boundary is stated in the type because the type is what a view receives:
 *
 *     proves     the transport is configured, encrypted, verified and
 *                authenticated, and that the sending domain publishes the
 *                authentication records this platform can observe
 *     cannot     whether a recipient's mailbox accepts the message, whether it
 *                reaches the inbox rather than spam, and whether the final
 *                sending infrastructure is in good standing
 *
 * The second list is not a caveat bolted on afterwards. Reputation, complaint
 * history and engagement are controlled by the receiving provider and by the
 * customer's mailbox; a sending application cannot observe or manufacture them.
 * A readiness report that omitted that would be implying an influence it does
 * not have.
 */
final readonly class DeliveryReadinessReport
{
    /**
     * @param  list<ReadinessFinding>  $findings
     */
    public function __construct(
        public array $findings,
        public bool $transportVerified,
    ) {}

    /**
     * What this report can and cannot establish.
     *
     * @return array{proves: list<string>, cannot_prove: list<string>}
     */
    public function limits(): array
    {
        return [
            'proves' => [
                'Whether the transport is configured, encrypted and authenticated.',
                'Whether the server accepted a message from these credentials.',
                'Whether the sending domain publishes SPF, DMARC and any verifiable DKIM selector.',
            ],
            'cannot_prove' => [
                'Whether a recipient receives the message.',
                'Whether it is placed in the inbox rather than spam.',
                'The reputation of the final sending infrastructure.',
                'Whether a third-party relay signs with DKIM as its own documentation claims.',
            ],
        ];
    }

    public function has(string $check): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->check === $check) {
                return true;
            }
        }

        return false;
    }

    public function level(string $check): ?ReadinessLevel
    {
        foreach ($this->findings as $finding) {
            if ($finding->check === $check) {
                return $finding->level;
            }
        }

        return null;
    }

    public function finding(string $check): ?ReadinessFinding
    {
        foreach ($this->findings as $finding) {
            if ($finding->check === $check) {
                return $finding;
            }
        }

        return null;
    }

    /**
     * Findings that prevent sending, or all of them when none do.
     *
     * Used by the pages to lead with the problem rather than burying it under
     * the checks that passed.
     *
     * @return list<ReadinessFinding>
     */
    public function blockers(): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (ReadinessFinding $finding): bool => $finding->level->isBlocking(),
        ));
    }

    /**
     * Findings worth a person's attention that do not block.
     *
     * @return list<ReadinessFinding>
     */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (ReadinessFinding $finding): bool => $finding->level === ReadinessLevel::Warn,
        ));
    }

    public function isReady(): bool
    {
        return $this->blockers() === [];
    }

    public function verdict(): string
    {
        return $this->isReady() ? 'Ready to send' : 'Action required';
    }

    /**
     * @return list<array{check: string, level: string, detail: string}>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (ReadinessFinding $f): array => [
                'check' => $f->check,
                'level' => $f->level->value,
                'detail' => $f->detail,
            ],
            $this->findings,
        );
    }
}

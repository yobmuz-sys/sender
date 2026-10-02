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
 *
 * One evaluation answers more than one question. The findings are computed once
 * and each carries the traffic it constrains, so {@see isReady()} can be asked
 * twice — transactionally and for bulk marketing — and get two answers that are
 * both true. Collapsing them into a single verdict is what produces the
 * misleading "SMTP: BROKEN" that a working transport with no DMARC record would
 * otherwise be reported as.
 */
final readonly class DeliveryReadinessReport
{
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
                'Whether a recipient mailbox exists, before the audience layer checks it.',
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

    /**
     * The finding itself, carrying the level observed under the strongest mode
     * it applies to. Use {@see levelIn()} to ask about a specific mode.
     */
    public function finding(string $check): ?ReadinessFinding
    {
        foreach ($this->findings as $finding) {
            if ($finding->check === $check) {
                return $finding;
            }
        }

        return null;
    }

    public function level(string $check): ?ReadinessLevel
    {
        return $this->finding($check)?->level;
    }

    /**
     * The level of one check as it applies to one kind of traffic.
     *
     * This is the accessor a view must use. {@see level()} reports the finding as
     * recorded, which is the bulk-sender reading, and reading it for a
     * transactional decision overstates what is wrong.
     */
    public function levelIn(string $check, TrafficMode $mode): ?ReadinessLevel
    {
        return $this->finding($check)?->levelFor($mode);
    }

    /**
     * Findings that prevent sending in the given mode, or all of them when none
     * do.
     *
     * Used by the pages to lead with the problem rather than burying it under
     * the checks that passed.
     *
     * @return list<ReadinessFinding>
     */
    public function blockers(TrafficMode $mode = TrafficMode::BulkMarketing): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (ReadinessFinding $finding): bool => $finding->blocksIn($mode),
        ));
    }

    /**
     * Findings worth a person's attention that do not block in the given mode.
     *
     * @return list<ReadinessFinding>
     */
    public function warnings(TrafficMode $mode = TrafficMode::BulkMarketing): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (ReadinessFinding $finding): bool => $finding->levelFor($mode) === ReadinessLevel::Warn,
        ));
    }

    public function isReady(TrafficMode $mode = TrafficMode::BulkMarketing): bool
    {
        return $this->blockers($mode) === [];
    }

    public function verdict(TrafficMode $mode = TrafficMode::BulkMarketing): string
    {
        return $this->isReady($mode) ? 'Ready to send' : 'Action required';
    }

    /**
     * The transport's own health, independent of what it would be used for.
     *
     * This is the answer to "is this SMTP account working", and it is deliberately
     * not the same question as {@see isReady()}. A transport with no DMARC record
     * is technically healthy and not bulk-ready, and a page that shows only one
     * of those facts misreports the other.
     */
    public function transportStatus(TrafficMode $mode = TrafficMode::Transactional): string
    {
        return $this->isReady($mode) ? 'Ready' : 'Needs attention';
    }

    /**
     * Whether the transport is technically sound, whatever it is being used for.
     */
    public function isTransportHealthy(TrafficMode $mode = TrafficMode::Transactional): bool
    {
        return $this->isReady($mode);
    }

    /**
     * Findings that block bulk marketing but not transactional mail.
     *
     * These are the gaps that matter only when a campaign is about to be sent,
     * which is why they are surfaced separately instead of being either hidden
     * or folded into the transport verdict.
     *
     * @return list<ReadinessFinding>
     */
    public function bulkOnlyBlockers(): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (ReadinessFinding $finding): bool => $finding->blocksIn(TrafficMode::BulkMarketing)
                && ! $finding->blocksIn(TrafficMode::Transactional),
        ));
    }

    /**
     * @return list<array{check: string, level: string, detail: string, scope: string, transactional_level: string, bulk_level: string}>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (ReadinessFinding $f): array => [
                'check' => $f->check,
                'level' => $f->level->value,
                'detail' => $f->detail,
                'scope' => $f->scope->value,
                'transactional_level' => $f->levelFor(TrafficMode::Transactional)->value,
                'bulk_level' => $f->levelFor(TrafficMode::BulkMarketing)->value,
            ],
            $this->findings,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * How severe one deliverability finding is.
 *
 * Four states, and the fourth is the one that carries the weight.
 *
 * There is no numerical score here on purpose. Any such number invites the
 * reading "87/100, therefore deliverable", and the platform has no way to
 * compute one: inbox placement is decided by receiving providers using signals
 * this application cannot observe — a sender's complaint history, engagement, a
 * blocklist this host never sees. A score would be arithmetic over the facts we
 * *can* see and labelled as something we cannot, which is worse than reporting
 * nothing because it invites a decision the number should not support.
 *
 * So each fact is reported at the strength it was actually established, and
 * {@see Unknown} is a first-class outcome. Turning an unknown into a pass would
 * make the report agree with the tenant rather than with the evidence.
 */
enum ReadinessLevel: string
{
    case Pass = 'pass';

    case Warn = 'warn';

    case Block = 'block';

    /**
     * The application could not establish this fact.
     *
     * Not a pass and not a failure. It is the honest answer for DKIM without a
     * known selector, and for a policy that depends on a stage not yet built.
     */
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Pass => 'Pass',
            self::Warn => 'Warning',
            self::Block => 'Blocked',
            self::Unknown => 'Not established',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pass => 'bg-emerald-100 text-emerald-800',
            self::Warn => 'bg-amber-100 text-amber-900',
            self::Block => 'bg-rose-100 text-rose-900',
            self::Unknown => 'bg-slate-100 text-slate-700',
        };
    }

    /**
     * Whether this finding alone prevents sending.
     */
    public function isBlocking(): bool
    {
        return $this === self::Block;
    }
}

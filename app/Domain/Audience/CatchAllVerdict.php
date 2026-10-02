<?php

declare(strict_types=1);

namespace App\Domain\Audience;

/**
 * Whether a domain discriminates between real and imaginary mailboxes.
 *
 * A tri-state rather than a boolean, because the three possible answers lead to
 * three different behaviours and collapsing any two of them would invent
 * certainty:
 *
 *     Yes       acceptance proves nothing; every mailbox at the domain is UNKNOWN
 *     No        acceptance is meaningful; individual mailbox checks may proceed
 *     Unknown   we could not find out, so we may not assume acceptance means
 *               anything — and equally may not declare the domain catch-all
 *
 * The `Unknown` case is the one most likely to be got wrong by simplification,
 * and it is the common one on shared hosting where outbound port 25 is blocked.
 * Treating it as `No` would mark every address at an unreachable domain as
 * active. Treating it as `Yes` would mark every address at a perfectly ordinary
 * domain as unknown and refuse to send to anyone. Both are wrong, and both are
 * silent.
 */
enum CatchAllVerdict: string
{
    case Yes = 'yes';

    case No = 'no';

    case Unknown = 'unknown';

    /**
     * Whether this verdict removes the ability to confirm any mailbox here.
     *
     * Only a positive detection does. `Unknown` does not, because it is a gap in
     * the evidence rather than a fact about the domain — the pipeline continues
     * and reports what the mailbox check itself found.
     */
    public function suppressesMailboxResults(): bool
    {
        return $this === self::Yes;
    }

    public function label(): string
    {
        return match ($this) {
            self::Yes => 'Accepts any address',
            self::No => 'Distinguishes real mailboxes',
            self::Unknown => 'Not established',
        };
    }
}

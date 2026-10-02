<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * What this particular SMTP account can currently be used for.
 *
 * Deliberately not `CapabilityStatus`. That answers "can this installation do
 * X at all", is memoised for a request, and is reported globally. An account's
 * usability is a different question with a different truth: it changes when one
 * host starts rejecting one account's credentials, and it is per tenant. Folding
 * account state into the capability vocabulary would produce answers like
 * "this installation's SMTP is DEGRADED", which is true and useless.
 *
 * The states are ordered by how much they permit, so callers that need a
 * boolean cannot accidentally read `isTerminal()` as `isUsable()`.
 */
enum SmtpAccountStatus: string
{
    /**
     * Stored, never successfully verified.
     */
    case Unverified = 'unverified';

    /**
     * Verified recently and within its freshness window.
     */
    case Ready = 'ready';

    /**
     * Was verified, but that verification has aged past the window.
     *
     * Distinct from UNVERIFIED because something *was* proved once, and an
     * operator looking at a stalled account needs to know whether to expect it
     * to work at all.
     */
    case Stale = 'stale';

    /**
     * Verification failed, or the last attempt did.
     */
    case Failed = 'failed';

    /**
     * Switched off — by an operator, or automatically after an authentication
     * failure. Not a failure: it is a decision.
     */
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Unverified => 'Not verified',
            self::Ready => 'Ready',
            self::Stale => 'Verification expired',
            self::Failed => 'Failed',
            self::Disabled => 'Disabled',
        };
    }

    /**
     * Whether this account may currently be used to send.
     *
     * Only READY. Not STALE, not UNVERIFIED: an account nobody has proved is
     * reachable is an account that will fail during a send, and failing during
     * a send is far more expensive than refusing up front.
     */
    public function isUsable(): bool
    {
        return $this === self::Ready;
    }

    /**
     * Whether an operator has deliberately switched this account off.
     *
     * Kept separate from `isUsable()` because the remedy differs: a FAILED
     * account needs re-verification, a DISABLED one needs a decision from a
     * person.
     */
    public function isSwitchedOff(): bool
    {
        return $this === self::Disabled;
    }

    /**
     * Whether a human must act before this account can send.
     */
    public function needsAttention(): bool
    {
        return $this !== self::Ready;
    }
}

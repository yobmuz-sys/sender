<?php

declare(strict_types=1);

namespace App\Domain\Audience;

/**
 * Why a recipient must never be contacted again by a tenant.
 *
 * The reason matters after the fact. A hard bounce and a complaint are very
 * different events: one says the address is gone, the other says the recipient
 * regarded the message as unwelcome. They are treated identically for *sending* —
 * both exclude — and differently for *reporting*, because a tenant whose list is
 * mostly complaints has a different problem from one whose list has rotted.
 *
 * `Unsubscribed` and `Complaint` are the two a recipient caused, and both are
 * terminal in a way `Manual` is not. The platform never lifts an unsubscribe; the
 * recipient asked to stop, and asking again is how a sender becomes a nuisance.
 * An operator can lift a manual suppression, and may lift an administrative one,
 * because neither was the recipient's own request.
 */
enum SuppressionReason: string
{
    /**
     * The recipient asked to stop. Terminal: never lifted by this platform.
     */
    case Unsubscribed = 'unsubscribed';

    /**
     * The recipient's server reported the address does not exist.
     *
     * Populated by the bounce-processing stage, which does not exist yet. The
     * vocabulary is defined now so that a later stage populates an existing
     * column rather than inventing a new one mid-send.
     */
    case HardBounce = 'hard_bounce';

    /**
     * The recipient reported the message as spam.
     *
     * Also populated by a later stage. Weighted far more heavily than a bounce in
     * practice — a complaint is a statement about the sender, not the address —
     * and it is never lifted automatically.
     */
    case Complaint = 'complaint';

    /**
     * The tenant suppressed this address themselves.
     */
    case Manual = 'manual';

    /**
     * An operator suppressed this address on the tenant's behalf.
     */
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Unsubscribed => 'Unsubscribed',
            self::HardBounce => 'Hard bounce',
            self::Complaint => 'Spam complaint',
            self::Manual => 'Suppressed by you',
            self::Admin => 'Suppressed by an administrator',
        };
    }

    public function explanation(): string
    {
        return match ($this) {
            self::Unsubscribed => 'This person asked to stop receiving email. We never lift this automatically.',
            self::HardBounce => 'Their mail server reported that this mailbox does not exist.',
            self::Complaint => 'This person reported a message as spam.',
            self::Manual => 'You chose not to contact this address.',
            self::Admin => 'An administrator chose not to contact this address.',
        };
    }

    /**
     * Whether this reason may ever be cleared.
     *
     * An unsubscribe and a complaint are the recipient exercising a right or
     * expressing a harm. Reinstating either without the recipient asking is the
     * behaviour that turns a suppression list into a recurring complaint source,
     * so this platform does not offer the operation at all.
     */
    public function canBeCleared(): bool
    {
        return ! in_array($this, [self::Unsubscribed, self::Complaint], true);
    }

    /**
     * Whether the recipient or the sender caused this.
     */
    public function wasRecipientInitiated(): bool
    {
        return in_array($this, [self::Unsubscribed, self::Complaint], true);
    }

    /**
     * The badge tone, so a suppression never renders as two different things.
     *
     * `rose` for the two the recipient caused, because they are the states a
     * customer most needs to notice and least able to undo. `amber` for a bounce,
     * which is a real fact about the address but one the sender can act on.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Unsubscribed, self::Complaint => 'rose',
            self::HardBounce => 'amber',
            self::Manual, self::Admin => 'slate',
        };
    }
}

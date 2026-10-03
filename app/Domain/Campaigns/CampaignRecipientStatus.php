<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

/**
 * Where one recipient of one campaign stands.
 *
 * Six states, and the pair that matters most is `sent` and `failed`. `sent` means
 * the transport accepted responsibility for this message and nothing more — not
 * that it reached an inbox, not that it was read, and certainly not that it was
 * wanted. Bounce and complaint ingestion is a later stage, and this vocabulary is
 * written so that when it arrives it has somewhere correct to land rather than
 * overloading `sent`.
 *
 * `skipped` and `blocked` are also distinct, and the difference is whether
 * anything was attempted:
 *
 *   - `blocked` means the platform refused before contacting the server. A
 *     recipient who unsubscribed after the campaign was prepared is blocked: the
 *     message was never built, never submitted, and the platform is the reason.
 *   - `skipped` means there was nothing to do, without a decision. A contact
 *     deleted from the platform before its turn came up is skipped.
 *
 * Merging them would make "we did not mail them" and "there was nobody to mail"
 * indistinguishable, which is exactly the question a customer asks about a number
 * that did not go out.
 *
 * `sending` exists only while a worker holds the claim. A recipient found in that
 * state with a stale claim was left by a killed worker, and is recoverable — see
 * the runner.
 */
enum CampaignRecipientStatus: string
{
    case Queued = 'queued';

    case Sending = 'sending';

    case Sent = 'sent';

    case Failed = 'failed';

    case Skipped = 'skipped';

    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Waiting',
            self::Sending => 'Sending',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
            self::Blocked => 'Blocked',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Queued => 'slate',
            self::Sending => 'amber',
            self::Sent => 'emerald',
            self::Failed => 'rose',
            self::Skipped => 'slate',
            self::Blocked => 'slate',
        };
    }

    /**
     * Whether no further attempt will be made for this recipient.
     *
     * Used to answer "is this campaign finished", so it must include everything
     * that will never be tried again. `sending` is deliberately not terminal: a
     * worker holds it now and it becomes one of these within a second.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Sent, self::Failed, self::Skipped, self::Blocked], true);
    }

    /**
     * Whether this is an outcome that will never be attempted again *after a send
     * was attempted*. Used for the progress figure, where a blocked recipient has
     * been dealt with but was never a message.
     */
    public function countsAsSent(): bool
    {
        return $this === self::Sent;
    }

    /**
     * Whether the worker may still pick this recipient up.
     */
    public function isPending(): bool
    {
        return in_array($this, [self::Queued, self::Sending], true);
    }

    /**
     * What this state means for this person, in one sentence.
     *
     * The label says where the recipient stands; this says why, which is the
     * question the recipient log raises. `failed` in particular needs its reasons
     * separated — "the server refused this address" and "the transport broke" are
     * different problems with different remedies, and a single word cannot say
     * which one happened. The stored reason beside it has the specifics.
     */
    public function explanation(): string
    {
        return match ($this) {
            self::Queued => 'Waiting to be sent. Nothing has been submitted for this address yet.',
            self::Sending => 'A worker has claimed this recipient and is submitting it right now.',
            self::Sent => 'A receiving server accepted this message. That is not a promise it arrived in an inbox.',
            self::Failed => 'This message will not be retried. See the reason beside it.',
            self::Skipped => 'There was nobody to send to: the contact was deleted before this turn came up. No server was contacted.',
            self::Blocked => 'The platform refused before contacting any server, because this person had asked not to be contacted.',
        };
    }
}

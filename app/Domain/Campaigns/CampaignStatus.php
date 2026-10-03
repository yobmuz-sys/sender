<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

/**
 * Where a campaign is in its life.
 *
 * Seven states, and the distinctions between them are the ones somebody operating
 * this at 2am needs: a campaign that has not started, one waiting for a time the
 * customer chose, one actively sending, one stopped by a person, one stopped by a
 * problem, and two that will never send again.
 *
 * `Failed` is kept separate from `Paused` on purpose. A pause is a decision
 * somebody made and can undo; a failure is the platform having stopped because
 * continuing would be wrong — a transport that will not accept mail, or a
 * preflight condition that reappeared. Collapsing them would leave a customer
 * looking for a "Resume" button on a campaign that cannot safely resume.
 *
 * There is no `sending` state here and no `retrying`: pacing and retries live on
 * the recipients, as timestamps, because they are per-recipient facts. A campaign
 * is running while any of its recipients remain due.
 */
enum CampaignStatus: string
{
    case Draft = 'draft';

    case Scheduled = 'scheduled';

    case Running = 'running';

    case Paused = 'paused';

    case Completed = 'completed';

    case Cancelled = 'cancelled';

    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Running => 'Sending',
            self::Paused => 'Paused',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Failed => 'Stopped by a problem',
        };
    }

    /**
     * Badge tone, so a status never looks like a different meaning on two pages.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'slate',
            self::Scheduled => 'sky',
            self::Running => 'amber',
            self::Paused => 'amber',
            self::Completed => 'emerald',
            self::Cancelled => 'slate',
            self::Failed => 'rose',
        };
    }

    /**
     * What this state means in a sentence, for the campaign page.
     */
    public function explanation(): string
    {
        return match ($this) {
            self::Draft => 'Not started. Nothing has been sent, and everything about it can still be changed.',
            self::Scheduled => 'Waiting for the time you chose. It will begin when the worker next runs after that moment.',
            self::Running => 'Sending. Messages go out one at a time, spaced by the minimum interval you set.',
            self::Paused => 'Stopped by you. Recipients already sent to are not recalled, and the rest are waiting.',
            self::Completed => 'Every recipient has been dealt with: sent, failed, skipped or blocked.',
            self::Cancelled => 'Cancelled. Nothing further will be sent from this campaign.',
            self::Failed => 'Stopped because of a problem, not a decision. The reason is on this page.',
        };
    }

    /**
     * Whether this campaign will never change state again.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::Failed], true);
    }

    /**
     * Whether the campaign has been launched, and therefore owns a frozen copy of
     * its message and audience.
     */
    public function hasLaunched(): bool
    {
        return ! in_array($this, [self::Draft, self::Scheduled], true);
    }

    /**
     * Whether the configuration may still be changed.
     *
     * A draft is entirely editable. A scheduled campaign may change when it starts
     * and how fast it goes, because neither has happened yet — but not which
     * template or which list, because the customer's mental model of "what am I
     * about to send" is attached to those and changing them under a scheduled send
     * would be a surprise. Anything launched is immutable except its pace.
     */
    public function allowsConfigurationEdit(): bool
    {
        return in_array($this, [self::Draft, self::Scheduled], true);
    }

    /**
     * Whether this campaign may be started, from this state.
     */
    public function allowsStart(): bool
    {
        return $this === self::Draft;
    }

    public function allowsPause(): bool
    {
        return $this === self::Running;
    }

    public function allowsResume(): bool
    {
        return $this === self::Paused;
    }

    /**
     * Whether a person may cancel it.
     *
     * A completed campaign cannot be cancelled: there is nothing left to stop, and
     * reporting "cancelled" on finished work would make the history wrong.
     */
    public function allowsCancel(): bool
    {
        return ! $this->isTerminal();
    }
}

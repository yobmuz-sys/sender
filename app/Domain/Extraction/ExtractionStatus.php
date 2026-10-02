<?php

declare(strict_types=1);

namespace App\Domain\Extraction;

/**
 * Where a task is in the pipeline that turns input into a checked audience.
 *
 * The vocabulary changed in Stage 5B, and the change is not cosmetic. The old
 * three states described one job:
 *
 *     pending -> processing -> completed | failed
 *
 * which made "still extracting" and "checking ten thousand addresses" look
 * identical from outside. One badge, one word, for two stages with wildly
 * different costs and wildly different failure modes. A customer watching 0%
 * could not tell a stuck extraction from a long validation, and neither could an
 * operator.
 *
 * The states are now the stages:
 *
 *     queued     -> extracting -> validating -> ready
 *                  |              |
 *                  +--------------+--> failed
 *                                  +--> cancelled
 *
 * `validating` is the state that earns the rest. It is what a badge shows while
 * a second, separate job checks the addresses the first one found — work that
 * used to be invisible, and work that is the expensive half.
 *
 * `ready` is terminal and means *no further work is scheduled*, not "everything
 * was checked". A list extracted before validation existed reaches `ready` with
 * nothing validated, and the report says so. Fabricating a progress figure to
 * cover that gap would be worse than the gap.
 *
 * `cancelled` exists for one reason: a queued task behind a long-running one is
 * a thing a person may want to abandon, and a badge they can watch sit in a queue
 * they cannot leave is a badge that teaches them the product does not respond to
 * them.
 */
enum ExtractionStatus: string
{
    case Queued = 'queued';

    case Extracting = 'extracting';

    case Validating = 'validating';

    case Ready = 'ready';

    case Failed = 'failed';

    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Waiting',
            self::Extracting => 'Finding addresses',
            self::Validating => 'Checking addresses',
            self::Ready => 'Finished',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * The stage name shown on a badge, which is more specific than the label.
     *
     * "Checking addresses" and "82% of 9,742 checked" are two facts, and pairing
     * them is what makes a running task legible rather than merely non-empty.
     */
    public function stage(): string
    {
        return match ($this) {
            self::Queued => 'QUEUED',
            self::Extracting => 'EXTRACTING',
            self::Validating => 'VALIDATING',
            self::Ready => 'READY',
            self::Failed => 'FAILED',
            self::Cancelled => 'CANCELLED',
        };
    }

    /**
     * Whether no further work will happen without an operator.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Ready, self::Failed, self::Cancelled], true);
    }

    /**
     * Whether this state counts against the tenant's one-active-task allowance.
     *
     * The question is not "is this task finished" but "is this task holding the
     * tenant's attention". A queued task is waiting its turn and must not start a
     * second pipeline alongside the first; a terminal one has let go.
     */
    public function holdsTheQueue(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * Whether the task has produced results that are worth showing.
     *
     * A failed extraction may have written some results before failing, and
     * showing them is honest. A queued one has none.
     */
    public function hasResults(): bool
    {
        return $this !== self::Queued;
    }

    /**
     * The badge tone, from one mapping so a state never means two things.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Queued => 'slate',
            self::Extracting, self::Validating => 'amber',
            self::Ready => 'emerald',
            self::Failed => 'rose',
            self::Cancelled => 'slate',
        };
    }
}

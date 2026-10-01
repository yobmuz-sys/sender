<?php

declare(strict_types=1);

namespace App\Domain\Extraction;

/**
 * The lifecycle of a stored extraction.
 *
 * Explicit transitions rather than a free-text status, because the operational
 * question an operator has is "is my extraction stuck, and where?", and that
 * question is only answerable if the possible answers are known. A row that
 * says `processing` forever is a distinct signal from one that says `failed`.
 *
 * The transitions are:
 *
 *     Pending -> Processing -> Completed
 *                           -> Failed
 *
 * `Pending` is the only state a customer creates. `Processing` is set by the
 * worker on pickup, which is also what `started_at` records. `Failed` is
 * terminal and visible to the customer rather than hidden behind a generic
 * "something went wrong".
 */
enum ExtractionStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }

    /**
     * Whether results are meaningful to show.
     *
     * A failed extraction may have written some results before failing, and
     * showing them is honest. A pending one has none.
     */
    public function hasResults(): bool
    {
        return $this !== self::Pending;
    }

    /**
     * The badge tone used by the shared status component.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'slate',
            self::Processing => 'amber',
            self::Completed => 'emerald',
            self::Failed => 'red',
        };
    }
}

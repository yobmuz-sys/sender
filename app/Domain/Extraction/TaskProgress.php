<?php

declare(strict_types=1);

namespace App\Domain\Extraction;

use App\Models\Extraction;

/**
 * How far through checking a task is, and whether a percentage can honestly be
 * shown at all.
 *
 * The temptation with a progress bar is to divide by the number of addresses
 * found and render whatever comes out. That is wrong more often than it is
 * right, in both directions, and both errors are visible to a customer:
 *
 *   - before validation has started, the denominator is real and the numerator is
 *     zero, so the badge claims to be 0% of something it has not begun
 *   - on a task that was extracted before validation existed, or one that failed
 *     part-way, there is no denominator at all, and dividing by zero is either a
 *     division-by-zero error or a 100% bar over a list nobody checked
 *
 * So the percentage exists only when both numbers are real, and
 * {@see hasDenominator()} is the gate. A badge that cannot state a percentage
 * says what it *can* state — "not started", "9,742 of 9,742 checked" — which is
 * always true, and is the whole reason this is a type rather than a division in
 * a template.
 */
final readonly class TaskProgress
{
    private function __construct(
        public ExtractionStatus $status,
        public int $found,
        public int $processed,
    ) {}

    public static function of(Extraction $extraction): self
    {
        return new self(
            $extraction->status,
            (int) $extraction->found_count,
            (int) $extraction->validation_processed_count,
        );
    }

    /**
     * Whether a real denominator exists to divide by.
     *
     * False for a queued task (nothing to show yet), for one that found nothing
     * (zero of zero is not a percentage), and for any task whose checking never
     * began.
     */
    public function hasDenominator(): bool
    {
        return $this->found > 0 && $this->processed > 0;
    }

    /**
     * Completion as a whole percentage, or null when one cannot be stated.
     *
     * Clamped rather than trusted: a retried job that committed a batch twice, or
     * a counter written by a concurrent pass, must not produce a bar running off
     * the end of its own track.
     */
    public function percent(): ?int
    {
        if (! $this->hasDenominator()) {
            return null;
        }

        return (int) min(100, max(0, (int) floor($this->processed / $this->found * 100)));
    }

    /**
     * The width style for the bar.
     *
     * Zero rather than null when there is no figure, so a template can render a
     * definite element and simply not fill it, instead of branching on null in
     * three places and getting one of them wrong.
     */
    public function barWidth(): string
    {
        // Zero rather than an empty percentage when there is no figure, so a
        // template can render a definite element and simply not fill it.
        // Interpolating the null directly would produce a bare "%" — not a
        // percentage and not a valid width, so the bar would collapse rather
        // than sit at zero.
        return ($this->percent() ?? 0).'%';
    }

    /**
     * One line a person can read, whatever the state.
     *
     * @return array{title: string, detail: string}
     */
    public function label(): array
    {
        if ($this->status === ExtractionStatus::Queued) {
            return [
                'title' => 'Waiting to start',
                'detail' => 'This task has not been picked up yet. Nothing needs doing.',
            ];
        }

        if ($this->status === ExtractionStatus::Extracting) {
            return [
                'title' => 'Finding addresses',
                'detail' => $this->found > 0
                    ? number_format($this->found).' found so far. Checking begins when this stage finishes.'
                    : 'Looking for email addresses in your input.',
            ];
        }

        if ($this->status === ExtractionStatus::Validating) {
            return [
                'title' => 'Checking addresses '.number_format($this->processed)
                    .' of '.number_format($this->found),
                'detail' => $this->percent() === null
                    ? 'Checking has started.'
                    : $this->percent().'% checked.',
            ];
        }

        if ($this->status === ExtractionStatus::Ready) {
            return $this->processed > 0 ? [
                'title' => 'Checked '.number_format($this->processed).' of '.number_format($this->found),
                'detail' => 'This task has finished.',
            ] : [
                // The legacy shape: extracted, never checked. Said plainly rather
                // than as 0%, which would read as "checked nothing of nothing".
                'title' => 'Found '.number_format($this->found),
                'detail' => 'These addresses have not been checked.',
            ];
        }

        if ($this->status === ExtractionStatus::Cancelled) {
            return [
                'title' => 'Cancelled',
                'detail' => 'This task was stopped and will not be processed again.',
            ];
        }

        return [
            'title' => 'Failed',
            'detail' => 'This task stopped before it could finish.',
        ];
    }
}

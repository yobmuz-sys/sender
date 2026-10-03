<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

/**
 * How far through a campaign is, and whether a percentage can honestly be shown.
 *
 * Two rules, both learned the hard way from the extraction progress bar this is
 * modelled on:
 *
 *   - **The numerator is terminal recipients, not sent ones.** A campaign where
 *     4,000 of 5,000 have been sent and 900 were refused by the server is 98% done,
 *     and a bar reading 80% because it only counted successful sends would tell the
 *     customer their campaign was still four-fifths of the way through when the
 *     worker had nothing left to send. Which states count is
 *     {@see CampaignRecipientStatus::isTerminal()} — the same answer the campaign
 *     page gives to "is this finished", so the bar and the state cannot disagree.
 *   - **There is no percentage without a denominator.** A draft has no audience
 *     frozen, so its recipient table is empty and there is nothing to be a
 *     percentage *of*. Rather than render 0% — a claim that the campaign has done
 *     none of a work it has not started — the row says what is true, which is that
 *     nothing has been sent.
 *
 * Every figure is a count of durable recipient rows. Nothing here is derived from a
 * campaign's status, so a campaign a worker has just picked up and a campaign that
 * has been paused both report exactly what was and was not done.
 */
final readonly class CampaignProgress
{
    /**
     * @param  array<string, int>  $counts  recipient counts by status, plus `total`
     */
    private function __construct(
        public CampaignStatus $status,
        public bool $frozen,
        private array $counts,
    ) {}

    /**
     * @param  array<string, int>  $counts  recipient counts by status, plus `total`
     */
    public static function of(CampaignStatus $status, bool $frozen, array $counts): self
    {
        return new self($status, $frozen, $counts);
    }

    /**
     * Whether there is a real number of recipients to be part-way through.
     *
     * False for anything that has not been launched: the audience is frozen at
     * launch, so a draft's zero is an absence of data rather than a result.
     *
     * `$frozen` rather than the status, because a *scheduled* campaign has been
     * launched — it holds a snapshot and a recipient list, and is waiting — and
     * asking it for a percentage is asking a real question. A draft has nothing to
     * be a percentage of.
     */
    public function hasDenominator(): bool
    {
        return $this->frozen && $this->total() > 0;
    }

    /**
     * Completion as a whole percentage, or null when none can be stated.
     */
    public function percent(): ?int
    {
        if (! $this->hasDenominator()) {
            return null;
        }

        return (int) min(100, max(0, (int) floor($this->settled() / $this->total() * 100)));
    }

    /**
     * Everyone who will not be attempted again, whatever happened to them.
     */
    public function settled(): int
    {
        $settled = 0;

        foreach (CampaignRecipientStatus::cases() as $status) {
            if ($status->isTerminal()) {
                $settled += (int) ($this->counts[$status->value] ?? 0);
            }
        }

        return $settled;
    }

    /**
     * Everyone the worker may still pick up.
     */
    public function remaining(): int
    {
        $remaining = 0;

        foreach (CampaignRecipientStatus::cases() as $status) {
            if ($status->isPending()) {
                $remaining += (int) ($this->counts[$status->value] ?? 0);
            }
        }

        return $remaining;
    }

    public function total(): int
    {
        return (int) ($this->counts['total'] ?? 0);
    }

    public function count(CampaignRecipientStatus $status): int
    {
        return (int) ($this->counts[$status->value] ?? 0);
    }

    /**
     * One line a person can read, whatever the state.
     *
     * @return array{title: string, detail: string}
     */
    public function label(): array
    {
        if (! $this->frozen) {
            return [
                'title' => $this->status === CampaignStatus::Draft
                    ? 'Not started'
                    : 'Waiting for its start time',
                'detail' => 'Nobody has been contacted. The audience is copied when this campaign starts.',
            ];
        }

        if ($this->total() === 0) {
            return [
                'title' => 'Nobody to send to',
                'detail' => 'This campaign froze an audience of zero contacts, so it will not send anything.',
            ];
        }

        return [
            'title' => number_format($this->settled()).' of '.number_format($this->total()).' dealt with',
            'detail' => number_format($this->remaining()).' still to send.',
        ];
    }
}

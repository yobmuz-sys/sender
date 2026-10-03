<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

/**
 * One campaign as the index page needs it.
 *
 * The reason this type exists is that a list row has a different question to ask
 * than a detail page. The detail page can afford to ask the database about one
 * campaign; a list of twenty-five cannot, and a view that reached for
 * `$campaign->remainingCount()` inside a loop would issue twenty-five queries to
 * draw one screen. So the numbers are computed once, in the query, and everything
 * after that is arithmetic on values that are already loaded.
 *
 * It holds no rules of its own. Progress is {@see CampaignProgress}, the actions
 * are {@see CampaignAction} reading the campaign's own status, and every figure is
 * a count of recipient rows that actually exist. A new status or a new recipient
 * state therefore appears here without this file being edited — which is the test
 * of whether a read model is a read model or a second copy of the domain.
 */
final readonly class CampaignSummary
{
    /**
     * @param  bool  $frozen  whether the launch has copied a message and an audience
     *                        into this campaign
     */
    public function __construct(
        public Campaign $campaign,
        public CampaignProgress $progress,
        public bool $frozen,
    ) {}

    /**
     * @param  array<string, int>  $counts  recipient counts by status, plus `total`
     */
    public static function of(Campaign $campaign, array $counts): self
    {
        $frozen = $campaign->hasLaunched();

        return new self(
            $campaign,
            CampaignProgress::of($campaign->status, $frozen, $counts),
            $frozen,
        );
    }

    public function status(): CampaignStatus
    {
        return $this->campaign->status;
    }

    /**
     * The recipient figure the index leads with.
     *
     * "Audience" rather than "recipients" for a campaign that has not launched:
     * the number of people it will contact is not known until the snapshot is
     * built, and a draft row showing 0 under a heading called Recipients reads as
     * a campaign that reached nobody.
     */
    public function audienceCount(): ?int
    {
        return $this->frozen ? $this->progress->total() : null;
    }

    public function sentCount(): int
    {
        return $this->progress->count(CampaignRecipientStatus::Sent);
    }

    public function lastActivity(): ?string
    {
        $activity = $this->campaign->last_activity_at;

        if ($activity !== null) {
            return $activity->diffForHumans();
        }

        $scheduled = $this->campaign->scheduledLocalTime();

        return $scheduled !== null
            ? 'starts '.$scheduled->format('j M').' at '.$scheduled->format('H:i').' ('.$this->campaign->scheduledTimezone().')'
            : null;
    }

    /**
     * What may be done to this campaign from a list, in the order it should be
     * offered.
     *
     * Read off the status rather than decided here, so the index cannot offer a
     * button the detail page would refuse. Three consequences of reading the domain
     * rather than the brief's matrix, all deliberate:
     *
     *   - **A scheduled campaign cannot be paused.** Pause means "stop the sending
     *     and keep it resumable", and a campaign that has not sent anything has
     *     nothing to stop; resuming it would mark it running and send, which throws
     *     away the start time the customer chose. It can be cancelled, or started
     *     early, and that is the whole set of honest choices.
     *   - **A draft cannot be cancelled from here.** It has sent nothing and
     *     everything about it is still editable, so the list offers Edit and leaves
     *     the decision to the campaign's own page.
     *   - **Cancel is offered on the strength of the snapshot, not the status.** A
     *     campaign that has been launched and is merely waiting has recipients it
     *     will never contact, so it needs the same way out as a running one.
     *
     * @return list<CampaignAction>
     */
    public function actions(): array
    {
        $actions = [CampaignAction::Open];

        if ($this->status()->allowsConfigurationEdit()) {
            $actions[] = CampaignAction::Edit;
        }

        if ($this->status()->allowsPause()) {
            $actions[] = CampaignAction::Pause;
        }

        if ($this->status()->allowsResume()) {
            $actions[] = CampaignAction::Resume;
        }

        if ($this->frozen && $this->status()->allowsCancel()) {
            $actions[] = CampaignAction::Cancel;
        }

        return $actions;
    }
}

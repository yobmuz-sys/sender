<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Operator;

use App\Domain\Campaigns\Campaign;
use App\Domain\Campaigns\CampaignAction;
use App\Domain\Campaigns\CampaignInterruption;
use App\Domain\Campaigns\CampaignProgress;
use App\Domain\Campaigns\CampaignStatus;
use App\Domain\Campaigns\CampaignSummary;
use App\Domain\Mail\SmtpAccount;
use Illuminate\Support\Carbon;

/**
 * One campaign as an operator sees it: whose it is, what it is using, how far it
 * has got, and what may be done about it.
 *
 * Not a second `CampaignSummary`. The progress figures, the frozen status and the
 * interruption are the customer's own read models, reused as they are, because an
 * administrator looking at a campaign is looking at the same campaign. What is added
 * is the context an operator needs and a customer has no access to: the owner, the
 * transport, and the timestamps of the lifecycle rather than just its current state.
 *
 * The actions are the one deliberate difference. The customer's list offers Open and
 * Edit because both are things a customer may legitimately do; an operator gets
 * Pause, Resume and Cancel — the interventions with an operational purpose — and
 * never Edit, because rewriting somebody's frozen campaign is not a diagnostic act.
 * Legality still comes from the campaign's own state, and the endpoint asks again.
 */
final readonly class CampaignOperatorSummary
{
    public function __construct(
        public Campaign $campaign,
        public CampaignSummary $summary,
        public string $ownerName,
        public string $ownerEmail,
        public ?SmtpAccount $transport,
    ) {}

    public static function of(
        Campaign $campaign,
        CampaignSummary $summary,
        ?string $ownerName,
        ?string $ownerEmail,
        ?SmtpAccount $transport,
    ): self {
        return new self($campaign, $summary, $ownerName ?? 'Deleted account', $ownerEmail ?? '—', $transport);
    }

    public function progress(): CampaignProgress
    {
        return $this->summary->progress;
    }

    /**
     * Why this campaign is not sending, if it is not sending.
     *
     * The customer's own object, unmodified: an operator reading "the transport
     * stopped accepting mail" must be reading the sentence the campaign stopped
     * with, not a summary written for a different audience.
     */
    public function interruption(): ?CampaignInterruption
    {
        return CampaignInterruption::for($this->campaign);
    }

    /**
     * The first line of the recorded reason, for a table cell or an incident row.
     */
    public function problem(): ?string
    {
        $interruption = $this->interruption();

        if ($interruption === null) {
            return null;
        }

        return $interruption->detail;
    }

    /**
     * What may be done to this campaign from the administration area.
     *
     * @return list<CampaignAction>
     */
    public function actions(): array
    {
        $status = $this->campaign->status;

        return array_values(array_filter([
            $status->allowsPause() ? CampaignAction::Pause : null,
            $status->allowsResume() ? CampaignAction::Resume : null,
            $status->allowsCancel() ? CampaignAction::Cancel : null,
        ]));
    }

    /**
     * Whether this campaign claims to be doing work it is not doing.
     *
     * The status column says `running` for a campaign whose worker has not run in
     * two days, and that is correct — nothing in the platform knows it is stuck. The
     * difference is visible from a timestamp, so an operator can see it without a
     * health score being invented for them.
     */
    public function isStalled(): bool
    {
        if (! in_array($this->campaign->status, [CampaignStatus::Running, CampaignStatus::Scheduled], true)) {
            return false;
        }

        $last = $this->campaign->last_activity_at;

        return $last === null
            || $last->lessThan(now()->subMinutes(CampaignOperatorFilters::IDLE_AFTER_MINUTES));
    }

    /**
     * The moment worth sorting by: when it last did something, or when it was last
     * touched if it never has.
     *
     * Returned as the model's own Carbon rather than converted, so the value is
     * exactly what the column holds and no caller has to wonder which of the two
     * Carbon classes it was handed.
     */
    public function lastActivityAt(): ?Carbon
    {
        return $this->campaign->last_activity_at;
    }
}

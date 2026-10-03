<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Operator;

use App\Domain\Campaigns\Campaign;
use App\Domain\Campaigns\CampaignOperations;
use App\Domain\Campaigns\CampaignStatus;
use App\Domain\Campaigns\CampaignSummary;
use App\Domain\Campaigns\DeliveryAttempt;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * One campaign, assembled for an operator.
 *
 * A different question from the customer's page, and it is worth being precise
 * about the difference. The customer asks "what is happening to my campaign"; an
 * operator asks "whose campaign is this, what transport is it using, is the
 * transport the problem, and has it been like this since Tuesday". So this adds the
 * owner's identity, the transport, and the complete set of lifecycle timestamps —
 * including the ones the customer page has no reason to show, such as when it was
 * cancelled or when it was created.
 *
 * What it deliberately does not do is diagnose. The interruption, the last refusal
 * and the retry schedule are read from the domain's own records; nothing here
 * infers a cause, ranks a provider or suggests a remedy. An operator is being shown
 * what happened so that they can decide, not told what they should do about it.
 *
 * Recipient *summaries* rather than a recipient log: an operator asking about a
 * campaign with twelve thousand recipients wants the figures and the failures, and a
 * full table belongs on the campaign's own page where it is paged and filterable.
 */
final readonly class CampaignOperatorDetail
{
    /**
     * @param  array<string, int>  $counts  recipient counts by status, plus `total`
     */
    public function __construct(
        public Campaign $campaign,
        public CampaignSummary $summary,
        public CampaignOperatorSummary $operator,
        public CampaignOperations $operations,
        public array $counts,
        public ?User $owner,
        public int $attempts,
        public int $otherCampaignsByOwner,
        public int $campaignsOnSameTransport,
    ) {}

    public static function read(Campaign $campaign): self
    {
        $campaign->loadMissing(['list', 'smtpAccount', 'user']);

        $counts = $campaign->recipientCounts();
        $summary = CampaignSummary::of($campaign, $counts);
        $owner = $campaign->user;

        return new self(
            campaign: $campaign,
            summary: $summary,
            // Composed rather than duplicated: the row summary's action list is the
            // one definition of what may be done to a campaign from an operator's
            // page, and it is derived from the campaign's own state.
            operator: CampaignOperatorSummary::of(
                $campaign,
                $summary,
                $owner?->name,
                $owner?->email,
                $campaign->smtpAccount,
            ),
            operations: CampaignOperations::read($campaign, $summary),
            counts: $counts,
            owner: $owner,
            attempts: DeliveryAttempt::query()
                ->whereIn('campaign_recipient_id', $campaign->recipients()->select('id'))
                ->count(),
            otherCampaignsByOwner: $owner === null
                ? 0
                : Campaign::query()
                    ->where('user_id', $owner->id)
                    ->where('id', '!=', $campaign->id)
                    ->count(),

            /*
             * How many platform-wide campaigns share this transport.
             *
             * Read here rather than in the view so the number is part of the record
             * an operator is shown, and so it can be answered by the same index that
             * will eventually say "which campaigns are affected when this account
             * fails" — the question this exists for.
             */
            campaignsOnSameTransport: $campaign->smtp_account_id === null
                ? 0
                : Campaign::query()
                    ->where('smtp_account_id', $campaign->smtp_account_id)
                    ->count(),
        );
    }

    /**
     * Every lifecycle timestamp the campaign recorded, oldest first.
     *
     * @return list<array{label: string, at: CarbonImmutable|null}>
     */
    public function lifecycle(): array
    {
        $entries = [
            ['label' => 'Created', 'at' => $this->campaign->created_at],
            ['label' => 'Scheduled start', 'at' => $this->campaign->scheduled_at],
            ['label' => 'Started', 'at' => $this->campaign->started_at],
            ['label' => 'Paused', 'at' => $this->campaign->paused_at],
            ['label' => 'Last activity', 'at' => $this->campaign->last_activity_at],
            ['label' => 'Completed', 'at' => $this->campaign->completed_at],
            ['label' => 'Cancelled', 'at' => $this->campaign->cancelled_at],
        ];

        return array_values(array_filter(
            $entries,
            static fn (array $entry): bool => $entry['at'] !== null,
        ));
    }

    /**
     * Whether the campaign is in a state an operator could act on.
     */
    public function isTerminal(): bool
    {
        return in_array(
            $this->campaign->status,
            [CampaignStatus::Completed, CampaignStatus::Cancelled],
            true,
        );
    }
}

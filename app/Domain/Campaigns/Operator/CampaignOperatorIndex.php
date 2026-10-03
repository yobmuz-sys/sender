<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Operator;

use App\Domain\Campaigns\Campaign;
use App\Domain\Campaigns\CampaignCountColumns;
use App\Domain\Campaigns\CampaignRecipientStatus;
use App\Domain\Campaigns\CampaignStatus;
use App\Domain\Campaigns\CampaignSummary;
use App\Domain\Mail\SmtpAccount;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Every campaign on the platform, read the way an operator reads it.
 *
 * The query is the customer's, widened: same counts as correlated subqueries in the
 * same statement as the page, plus the owner and the transport joined in rather than
 * loaded afterwards. Nothing is aggregated in PHP. That is not a style preference —
 * this table is the one place the platform holds every tenant's campaigns at once,
 * so it is the page that would fall over first if it read rows and then counted
 * them, and the query-count test below is what keeps it honest.
 *
 * Three questions are answered here and nowhere else:
 *
 *   - **Where is everything?** `statusCountsFor()`, across all tenants.
 *   - **What needs a person?** `incidents()`, and it is first on the page rather
 *     than being a filter an operator has to think to apply.
 *   - **What is sending right now?** `sendingNow()`, which is a count of campaigns
 *     whose own state says `running` — not of campaigns that look busy.
 */
final class CampaignOperatorIndex
{
    /**
     * The states this table shows, and therefore the counts it selects.
     *
     * Narrower than the customer's index on purpose: an operator needs to know how
     * many have been dealt with, how many failed, and how many are outstanding.
     */
    private const COUNTED_STATES = [
        CampaignRecipientStatus::Sent,
        CampaignRecipientStatus::Failed,
        CampaignRecipientStatus::Queued,
        CampaignRecipientStatus::Sending,
    ];

    /**
     * @var list<CampaignStatus>
     */
    private const ATTENTION_ORDER = [
        CampaignStatus::Failed,
        CampaignStatus::Running,
        CampaignStatus::Paused,
        CampaignStatus::Scheduled,
        CampaignStatus::Draft,
        CampaignStatus::Completed,
        CampaignStatus::Cancelled,
    ];

    /**
     * How many incidents the page shows at once.
     *
     * Bounded on purpose: this is the list a person reads before deciding what to
     * do, and a page of four hundred stopped campaigns is not that list. The count
     * beside it says how many there are in total, and the filter can reach them.
     */
    private const INCIDENT_LIMIT = 10;

    public function __construct(
        private readonly int $perPage = 25,
    ) {}

    /**
     * A page of campaigns from any tenant, in operational order.
     *
     * @return LengthAwarePaginator<int, CampaignOperatorSummary>
     */
    public function paginate(CampaignOperatorFilters $filters): LengthAwarePaginator
    {
        return $this->query($filters)
            ->paginate($this->perPage)
            ->withQueryString()
            ->through(fn (Campaign $campaign): CampaignOperatorSummary => $this->summarise($campaign));
    }

    /**
     * Campaigns that are stopped and say why.
     *
     * Paused and failed only, and for the same reason the customer's page explains
     * those two differently: one is a decision somebody made and the other is the
     * platform having stopped. A draft is not an incident, and neither is a campaign
     * that finished.
     *
     * @return Collection<int, CampaignOperatorSummary>
     */
    public function incidents(): Collection
    {
        return $this->base()
            ->whereIn('campaigns.status', [
                CampaignStatus::Failed->value,
                CampaignStatus::Paused->value,
            ])
            ->reorder()
            ->orderByRaw('coalesce(campaigns.last_activity_at, campaigns.updated_at) desc')
            ->orderByDesc('campaigns.id')
            ->limit(self::INCIDENT_LIMIT)
            ->get()
            ->map(fn (Campaign $campaign): CampaignOperatorSummary => $this->summarise($campaign));
    }

    /**
     * How many campaigns are stopped, whether or not they fit in the incident list.
     */
    public function incidentCount(): int
    {
        return Campaign::query()
            ->whereIn('status', [
                CampaignStatus::Failed->value,
                CampaignStatus::Paused->value,
            ])
            ->count();
    }

    /**
     * Campaigns whose own state says they are sending.
     */
    public function sendingNow(): int
    {
        return Campaign::query()
            ->where('status', CampaignStatus::Running->value)
            ->count();
    }

    /**
     * How many campaigns platform-wide are in each state.
     *
     * @return array<string, int> status value => count
     */
    public function statusCountsFor(): array
    {
        $counts = Campaign::query()
            ->reorder()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $result = [];

        foreach (CampaignStatus::cases() as $status) {
            $result[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $result;
    }

    /**
     * Customers who own at least one campaign, with how many each.
     *
     * The filter is built from the campaigns themselves rather than from the users
     * table, so the dropdown offers exactly the owners a campaign list can return.
     * Selecting a user with no campaigns would produce an empty page and no
     * explanation.
     *
     * @return Collection<int, User>
     */
    public function owners(): Collection
    {
        return User::query()
            ->whereExists(function (QueryBuilder $query): void {
                $query->selectRaw('1')
                    ->from('campaigns')
                    ->whereColumn('campaigns.user_id', 'users.id');
            })
            // A correlated count rather than a relation: the user model deliberately
            // carries no domain relations, and this is the only place that needs to
            // know how many campaigns a customer has.
            ->select('users.*')
            ->selectSub(
                Campaign::query()
                    ->selectRaw('count(*)')
                    ->whereColumn('campaigns.user_id', 'users.id'),
                'campaigns_count',
            )
            ->orderByDesc('campaigns_count')
            ->orderBy('name')
            ->get();
    }

    /**
     * Transports that at least one campaign has used.
     *
     * Kept as its own method, and deliberately countable per transport later, so
     * that "which campaigns are affected when this account fails" is a question this
     * read model can answer without inventing a second relationship.
     *
     * @return Collection<int, SmtpAccount>
     */
    public function transports(): Collection
    {
        return SmtpAccount::query()
            ->whereExists(function (QueryBuilder $query): void {
                $query->selectRaw('1')
                    ->from('campaigns')
                    ->whereColumn('campaigns.smtp_account_id', 'smtp_accounts.id');
            })
            ->orderBy('label')
            ->get();
    }

    /**
     * How many campaigns are using one transport.
     *
     * The number an operator wants beside a failing account on the SMTP page, and
     * the reason the index selects its transport join at all.
     */
    public function campaignsUsingTransport(?int $transportId): int
    {
        if ($transportId === null) {
            return 0;
        }

        return Campaign::query()
            ->where('smtp_account_id', $transportId)
            ->count();
    }

    /**
     * The base query: every tenant, with the owner named and the counts attached.
     *
     * Shared by the page and the incident list so that the two cannot read a
     * campaign differently — an incident row that disagreed with the row beneath it
     * about how many people a campaign reached would be worse than no incident list.
     */
    private function base(): Builder
    {
        return Campaign::query()
            // Named in the join rather than eager-loaded per row, so the owner's
            // name costs nothing extra per row.
            ->join('users', 'users.id', '=', 'campaigns.user_id')
            ->select([
                'campaigns.*',
                'users.name as owner_name',
                'users.email as owner_email',
            ])
            ->with('smtpAccount')
            ->withCount(CampaignCountColumns::withCounts(self::COUNTED_STATES));
    }

    /**
     * The base query, filtered and ordered.
     */
    private function query(CampaignOperatorFilters $filters): Builder
    {
        $query = $this->base();

        $filters->applyTo($query);

        return $query
            ->reorder()
            ->orderByRaw($this->rankExpression())
            ->orderByRaw('coalesce(campaigns.last_activity_at, campaigns.updated_at) desc')
            ->orderByDesc('campaigns.id');
    }

    /**
     * One row, assembled from what the query selected.
     */
    private function summarise(Campaign $campaign): CampaignOperatorSummary
    {
        return CampaignOperatorSummary::of(
            $campaign,
            CampaignSummary::of($campaign, CampaignCountColumns::from($campaign)),
            $campaign->owner_name,
            $campaign->owner_email,
            $campaign->smtpAccount,
        );
    }

    /**
     * Failed first, then running, then stopped-by-choice: the order in which an
     * operator wants to read a platform-wide list.
     */
    private function rankExpression(): string
    {
        // Qualified with the table name, in the `case` and not the values: this
        // query joins `users` to name the owner, and that table has a status of its
        // own.
        return 'case campaigns.status '.implode(' ', array_map(
            static fn (CampaignStatus $status, int $rank): string => sprintf(
                "when '%s' then %d",
                $status->value,
                $rank,
            ),
            self::ATTENTION_ORDER,
            array_keys(self::ATTENTION_ORDER),
        )).' else 99 end';
    }
}

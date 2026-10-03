<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use App\Domain\Mail\SmtpAccount;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Reads the campaign list, and is the only place that knows how.
 *
 * Three things make this a type rather than a query in a controller:
 *
 *   - **The counts are subqueries.** Every figure on a list row is a count of
 *     recipient rows, and a row-by-row `recipientCounts()` would be one grouped
 *     query per campaign. Here they are correlated subqueries selected in the same
 *     statement as the page of campaigns, so the whole screen is two queries
 *     regardless of how many campaigns there are. The index they use already
 *     exists: `campaign_recipients (campaign_id, status, next_attempt_at)`.
 *   - **The status counts describe the whole tenant, not the page.** A row of
 *     figures that changed when you filtered would be a lie about how much work is
 *     outstanding, so the header counts ignore the filters and the list below them
 *     does not. The page says so.
 *   - **Ordering is operational, not chronological.** A customer opening this page
 *     is looking for whatever is moving. Campaigns that need a decision come first
 *     and sort by their own rank, then the ones that are over, then the drafts
 *     nobody has started.
 */
final class CampaignIndex
{
    /**
     * The order a customer needs to see campaigns in, which is not the order they
     * move through their lives.
     *
     * The lifecycle order — draft, scheduled, sending, paused, completed — is
     * correct for the figures along the top, where it reads as a summary of the
     * states there are. It is wrong for a list, because the campaign a customer
     * opens this page to find is the one that is sending, the one that stopped, or
     * the one somebody paused, and those three would be buried under a wall of
     * drafts. So the list sorts by "does this need me", and the header keeps the
     * lifecycle order.
     *
     * @var list<CampaignStatus>
     */
    private const ATTENTION_ORDER = [
        CampaignStatus::Running,
        CampaignStatus::Failed,
        CampaignStatus::Paused,
        CampaignStatus::Scheduled,
        CampaignStatus::Draft,
        CampaignStatus::Completed,
        CampaignStatus::Cancelled,
    ];

    public function __construct(
        private readonly int $perPage = 25,
    ) {}

    /**
     * A page of this tenant's campaigns, most operationally relevant first.
     *
     * @return LengthAwarePaginator<int, CampaignSummary>
     */
    public function paginateFor(User $user, CampaignIndexFilters $filters): LengthAwarePaginator
    {
        $campaigns = $this->query($user, $filters)
            ->paginate($this->perPage)
            ->withQueryString();

        return $campaigns->through(fn (Campaign $campaign): CampaignSummary => CampaignSummary::of(
            $campaign,
            CampaignCountColumns::from($campaign),
        ));
    }

    /**
     * How many campaigns this tenant has in each state.
     *
     * @return array<string, int> status value => count
     */
    public function statusCountsFor(User $user): array
    {
        $counts = Campaign::query()
            ->ownedBy((int) $user->id)
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
     * The transports offered as a filter, so the list is filterable by the account
     * a customer recognises rather than by an id.
     *
     * @return Collection<int, SmtpAccount>
     */
    public function accountsFor(User $user): Collection
    {
        return SmtpAccount::query()
            ->where('user_id', $user->id)
            ->orderBy('label')
            ->get();
    }

    /**
     * The base query: this tenant, filtered, with the counts attached.
     */
    private function query(User $user, CampaignIndexFilters $filters): Builder
    {
        $query = Campaign::query()
            ->ownedBy((int) $user->id)
            ->with(['list', 'smtpAccount'])
            ->withCount(CampaignCountColumns::withCounts());

        $filters->applyTo($query);

        return $query
            ->reorder()
            ->orderByRaw('case status '.$this->rankExpression().' end')
            ->orderByRaw('coalesce(last_activity_at, updated_at) desc')
            ->orderByDesc('id');
    }

    /**
     * The order, as a SQL case so the database sorts rather than PHP.
     *
     * Written out rather than generated so that the column order is visible where
     * the query is. A state missing from {@see ATTENTION_ORDER} would fall through
     * to 0 and jump to the top of the list, which is why the expression ends by
     * naming the default rather than leaving it to the database.
     */
    private function rankExpression(): string
    {
        return implode(' ', array_map(
            static fn (CampaignStatus $status, int $rank): string => sprintf("when '%s' then %d", $status->value, $rank),
            self::ATTENTION_ORDER,
            array_keys(self::ATTENTION_ORDER),
        )).' else 99';
    }
}

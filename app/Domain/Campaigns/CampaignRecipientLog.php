<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reads one campaign's recipients, a page at a time.
 *
 * The reason this is a type and not a query in the controller is the audience
 * size. A campaign that has actually run may have eleven thousand recipients, and
 * its attempt history could be several times that again. Rendering all of it would
 * put the whole audience — and every attempt ever made for it — into one HTTP
 * response, which is the unbounded read this codebase has removed everywhere else.
 *
 * So: a page of recipients, ordered so that the ones a customer is most likely
 * looking for come first — anything not yet settled, then the most recently
 * attempted, then the rest by address — and with the contact and the attempt
 * history for that page loaded in one go rather than a query per row. The total is
 * counted once by the paginator, and the attempt ceiling is four per recipient, so
 * a page of fifty rows carries a bounded amount of history.
 */
final class CampaignRecipientLog
{
    /**
     * The order recipients are read in: unsettled work first.
     *
     * Somebody opening this log during a send wants to know what is still going
     * out and what has just failed; the addresses accepted weeks ago are the least
     * interesting thing on the page and would otherwise fill every page of it.
     *
     * @var list<CampaignRecipientStatus>
     */
    private const READING_ORDER = [
        CampaignRecipientStatus::Sending,
        CampaignRecipientStatus::Queued,
        CampaignRecipientStatus::Failed,
        CampaignRecipientStatus::Skipped,
        CampaignRecipientStatus::Blocked,
        CampaignRecipientStatus::Sent,
    ];

    public function __construct(
        private readonly int $perPage = 50,
    ) {}

    /**
     * @return LengthAwarePaginator<int, CampaignRecipient>
     */
    public function paginateFor(
        Campaign $campaign,
        CampaignRecipientLogFilters $filters,
    ): LengthAwarePaginator {
        $query = CampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->with(['contact', 'deliveryAttempts']);

        $filters->applyTo($query);

        return $query
            ->reorder()
            ->orderByRaw('case status '.$this->orderExpression().' else 9 end')
            ->orderByDesc('last_attempt_at')
            ->orderBy('id')
            ->paginate($this->perPage)
            ->withQueryString();
    }

    /**
     * The reading order as a SQL case, so the database sorts it.
     *
     * Every value is interpolated from the enum's own `value` and quoted, rather
     * than typed into a string by hand — a hand-written one of these is how a
     * column name ends up unquoted and the query stops being SQL and starts being
     * a runtime error on a page nobody tested.
     */
    private function orderExpression(): string
    {
        return implode(' ', array_map(
            static fn (CampaignRecipientStatus $status, int $rank): string => sprintf(
                "when '%s' then %d",
                $status->value,
                $rank,
            ),
            self::READING_ORDER,
            array_keys(self::READING_ORDER),
        ));
    }

    /**
     * Recipients of one campaign, for a lookup that must not page.
     *
     * Only ever called for a specific campaign — the audience is a property of the
     * campaign in the URL, never of the request as a whole.
     *
     * @return Collection<int, CampaignRecipient>
     */
    public function forCampaign(Campaign $campaign): Collection
    {
        return CampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * The counts the filter is chosen from, in the order they are worth reading.
     *
     * Read once for the whole page rather than per request, and used to disable a
     * filter option that would return nothing — a dropdown offering "Failed (0)"
     * invites a click that produces an empty table and the suspicion of a bug.
     *
     * @return array<string, int>
     */
    public function statusCountsFor(Campaign $campaign): array
    {
        $counts = CampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->reorder()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $result = [];

        foreach (CampaignRecipientStatus::cases() as $status) {
            $result[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $result;
    }

    /**
     * Restrict a query to one campaign's recipients.
     *
     * Used by the controller wherever it queries recipients itself, so a recipient
     * row can never be reached by id alone — which is the only way a log could
     * cross a campaign boundary.
     */
    public function scopeTo(Builder $query, Campaign $campaign): Builder
    {
        return $query->where('campaign_id', $campaign->id);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use Illuminate\Database\Eloquent\Builder;

/**
 * The recipient-count subqueries a campaign list selects, in one place.
 *
 * Both campaign indexes need the same numbers and neither may compute them in
 * PHP: the customer's list and an administrator's list are the same question asked
 * of different rows, and the day those two pages disagree about how many people a
 * campaign sent to is the day somebody trusts the wrong one.
 *
 * So the counts are generated from {@see CampaignRecipientStatus} and selected as
 * correlated subqueries in the same statement as the page of campaigns, rather than
 * counted per row after the fact. A caller asks for the states its table displays,
 * so neither page pays for a column it does not show — and the resulting attributes
 * are the ones {@see CampaignSummary} already reads, which is what makes the two
 * pages agree rather than merely resemble each other.
 */
final class CampaignCountColumns
{
    /**
     * `withCount` definitions: the total, plus one per requested status.
     *
     * `queued` and `sending` are separate because they answer different questions
     * — waiting, and being submitted right now — even though progress sums them.
     *
     * @param  list<CampaignRecipientStatus>|null  $statuses  Null for every state.
     * @return array<string, \Closure(Builder): void>|string
     */
    public static function withCounts(?array $statuses = null): array
    {
        $definitions = ['recipients as recipients_total'];

        foreach ($statuses ?? CampaignRecipientStatus::cases() as $status) {
            $definitions['recipients as recipients_'.$status->value] = static function (Builder $query) use ($status): void {
                // Qualified with the table name. An administrator's list joins
                // `users` to name the owner, and both tables have a `status`
                // column, so an unqualified reference here is ambiguous — and it is
                // ambiguous inside the subquery too, because the correlated outer
                // table is in scope there as well.
                $query->where('campaign_recipients.status', $status->value);
            };
        }

        return $definitions;
    }

    /**
     * The counts a select attached, as the array the read model expects.
     *
     * @return array<string, int>
     */
    public static function from(Campaign $campaign): array
    {
        $counts = ['total' => (int) $campaign->recipients_total];

        foreach (CampaignRecipientStatus::cases() as $status) {
            $counts[$status->value] = isset($campaign->{'recipients_'.$status->value})
                ? (int) $campaign->{'recipients_'.$status->value}
                : 0;
        }

        return $counts;
    }
}

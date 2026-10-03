<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use App\Domain\Audience\AudienceEligibility;
use App\Models\ContactList;
use Illuminate\Support\Facades\DB;

/**
 * Freezes a campaign's audience at launch.
 *
 * The campaign's recipients are a copy, taken once, and the source list is neither
 * read again during the send nor modified by it. That is the whole point, and it
 * is worth being explicit about what it prevents:
 *
 *   - somebody adding a contact to the list mid-campaign does not put mail on the
 *     wire to somebody who was never eligible for it;
 *   - somebody deleting a contact does not retroactively change what a finished
 *     campaign reported it did;
 *   - a pause lasting a week does not quietly re-select a different audience when
 *     the campaign resumes.
 *
 * Excluded contacts are not deleted from the list and not marked unsubscribable —
 * they simply do not become rows here, and the count of them is reported. Removing
 * them from the customer's list would hide an exclusion in the last place they
 * would look for it.
 *
 * Selection reuses {@see AudienceEligibility} rather than repeating its predicate,
 * so a campaign cannot end up with a second, subtly different definition of
 * "somebody we may contact" from the one the list page reports against.
 */
class AudienceSnapshot
{
    /**
     * Rows inserted per statement. Bounded so a 100,000-recipient campaign does
     * not build one enormous INSERT.
     */
    private const CHUNK = 500;

    public function __construct(
        private readonly AudienceEligibility $eligibility,
        private readonly LogicalMessageId $messageIds,
    ) {}

    /**
     * What this campaign would reach, without writing anything.
     *
     * Used by the builder page, so the customer sees the consequences of a choice
     * before the campaign exists.
     */
    public function summaryFor(Campaign $campaign): AudienceSummary
    {
        $list = $campaign->list;

        if (! $list instanceof ContactList) {
            return new AudienceSummary(0, 0, 0, 0, 0, 0, 0, 0);
        }

        return $this->summarise($campaign, $list);
    }

    /**
     * Take the campaign's copy of its audience.
     *
     * Idempotent, deliberately: the launch path can be retried, and re-running it
     * must not produce two rows per contact. `insertOrIgnore` against the unique
     * index is what enforces that, so it does not depend on anybody having asked
     * "does this exist" first — the window between that question and the insert is
     * exactly where two concurrent launches would both see nothing.
     *
     * Rows are also written as `blocked` with a reason when the contact has been
     * suppressed between the preflight and now. Losing that row entirely would
     * make the campaign's totals silently smaller than its snapshot, and the
     * customer would have no way to tell that somebody unsubscribed a second ago.
     *
     * Every row is given its `Message-ID` here, at launch, rather than at the first
     * attempt. Launch is the moment the message becomes a thing: the content is
     * frozen and the audience is decided, so the logical message exists even for a
     * recipient that is never submitted to. A recipient that is suppressed still
     * gets one — it costs nothing, and a row that has an identifier only sometimes
     * is the kind of conditional that a later query has to remember.
     *
     * `insertOrIgnore` is what makes the retry safe: a second launch generates
     * fresh identifiers, and every one of them loses to the existing
     * `campaign_id + contact_id` index. The first launch's identifiers are the ones
     * that stand, and the unique index on `message_id` is a backstop rather than
     * the mechanism — two hundred and thirty-four random bits colliding is not a
     * thing that happens, and if it somehow did, the row would be dropped rather
     * than merged.
     */
    public function build(Campaign $campaign): AudienceSummary
    {
        $list = $campaign->list;

        if (! $list instanceof ContactList) {
            return new AudienceSummary(0, 0, 0, 0, 0, 0, 0, 0);
        }

        $suppressed = $this->suppressedContactIds($campaign);

        $now = now();
        $chunk = [];

        // No explicit `select()`, and the alias is given explicitly: `chunkById` reads
        // its position from the *hydrated attribute*, and a qualified column name
        // is not an attribute name. Without the alias Laravel looks for an
        // attribute called `contacts.id`, finds nothing, and refuses to continue.
        // The models are hydrated and unused — only the id is read.
        $this->eligibility->eligibleFor((int) $campaign->user_id, $list)
            ->orderBy('contacts.id')
            ->chunkById(self::CHUNK, function ($contacts) use ($campaign, $suppressed, $now, &$chunk): void {
                foreach ($contacts as $contact) {
                    $isSuppressed = in_array((int) $contact->id, $suppressed, true);

                    $chunk[] = [
                        'campaign_id' => $campaign->id,
                        'contact_id' => (int) $contact->id,

                        // Copied rather than joined, so a campaign keeps its own
                        // record of who it was going to contact even after the
                        // contact is deleted.
                        'email' => (string) $contact->email,
                        'message_id' => $this->messageIds->generate(),
                        'status' => $isSuppressed
                            ? CampaignRecipientStatus::Blocked->value
                            : CampaignRecipientStatus::Queued->value,
                        'attempts' => 0,
                        'next_attempt_at' => null,
                        'last_error_message' => $isSuppressed
                            ? 'This contact asked not to be contacted before the campaign started.'
                            : null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($chunk !== []) {
                    DB::table('campaign_recipients')->insertOrIgnore($chunk);
                    $chunk = [];
                }
            }, 'contacts.id', 'id');

        return $this->summarise($campaign, $list);
    }

    private function summarise(Campaign $campaign, ContactList $list): AudienceSummary
    {
        $counts = $this->eligibility->breakdownFor((int) $campaign->user_id, $list);

        $total = DB::table('list_contacts')
            ->where('list_id', $list->id)
            ->where('user_id', $campaign->user_id)
            ->count();

        return AudienceSummary::fromCounts((int) $total, $counts);
    }

    /**
     * Contact ids on this tenant's suppression list.
     *
     * Loaded once per build rather than tested per contact: a per-row query would
     * turn one bulk operation into a thousand, and the set cannot grow in a way
     * that matters — anyone suppressed during the build itself is caught by the
     * sender's own check before it submits anything.
     *
     * @return list<int>
     */
    private function suppressedContactIds(Campaign $campaign): array
    {
        return DB::table('suppressions')
            ->where('user_id', $campaign->user_id)
            ->pluck('contact_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}

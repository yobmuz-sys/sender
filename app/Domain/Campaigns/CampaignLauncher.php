<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use App\Domain\Templates\Template;
use Illuminate\Support\Facades\DB;

/**
 * Turns a prepared campaign into a frozen, running one.
 *
 * The order of the three things that happen here is the invariant, and it is not
 * arbitrary:
 *
 *   1. **preflight, on the server, now** — not the result the browser rendered, and
 *      not a result cached at save time;
 *   2. **freeze the template's content into the campaign**;
 *   3. **snapshot the audience into recipient rows.**
 *
 * Both writes happen in one transaction, so a campaign is either fully frozen with
 * its audience, or still a draft with none of it. A campaign that captured the
 * message but not its recipients — or the reverse — would be a state the rest of
 * the engine has no meaning for.
 *
 * The freeze is one-way. There is no `unfreeze()`, and adding one would defeat the
 * entire purpose: a launched campaign that could be re-snapshotted would be sending
 * words chosen after its first recipients had already received different words.
 */
class CampaignLauncher
{
    public function __construct(
        private readonly CampaignPreflight $preflight,
        private readonly AudienceSnapshot $audience,
    ) {}

    /**
     * Whether this campaign may be started right now.
     */
    public function mayLaunch(Campaign $campaign): bool
    {
        return $this->preflight->reportFor($campaign)->canLaunch();
    }

    /**
     * Start a campaign, or refuse with the reason.
     *
     * Returns null on success and the preflight report on refusal, so a caller
     * shows the customer the same list of checks it just enforced rather than
     * inventing a message.
     */
    public function launch(Campaign $campaign): ?CampaignPreflightReport
    {
        $report = $this->preflight->reportFor($campaign);

        if (! $report->canLaunch()) {
            return $report;
        }

        $template = $campaign->template;

        if (! $template instanceof Template) {
            // Unreachable while the preflight blocks on a missing template. Kept as
            // a hard guard rather than an assumption, because freezing a null
            // snapshot would produce a campaign that reports as launched and sends
            // an empty message.
            return $report;
        }

        $snapshot = $template->snapshot();

        DB::transaction(function () use ($campaign, $snapshot): void {
            $campaign->freeze($snapshot);
            $campaign->recipients()->delete();
            $campaign->save();

            $this->audience->build($campaign);

            $campaign->forceFill([
                'status' => CampaignStatus::Scheduled->value,
                'failure_reason' => null,
            ])->save();
        });

        return null;
    }

    /**
     * Launch now, skipping the scheduled start time.
     */
    public function launchNow(Campaign $campaign): ?CampaignPreflightReport
    {
        $refused = $this->launch($campaign);

        if ($refused !== null) {
            return $refused;
        }

        $campaign->markRunning();

        return null;
    }
}

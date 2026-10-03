<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Campaigns\Campaign;
use App\Domain\Campaigns\CampaignInterruption;
use App\Domain\Campaigns\CampaignProgress;
use App\Domain\Campaigns\CampaignRecipientStatus;
use App\Domain\Campaigns\CampaignStatus;
use App\Domain\Campaigns\CampaignSummary;
use App\Domain\Campaigns\Operator\CampaignOperatorDetail;
use App\Domain\Campaigns\Operator\CampaignOperatorFilters;
use App\Domain\Campaigns\Operator\CampaignOperatorIndex;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessCampaignJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Campaign operations, across tenants.
 *
 * An operator's question is not "what campaigns do I have" — the customer answers
 * that — it is "what is happening on this platform, whose is it, which transport is
 * involved, and is anything wrong". So this controller reads across every tenant and
 * deliberately decides very little.
 *
 * Three boundaries are worth stating, because they are what keeps this page from
 * becoming the way the platform's safeguards get circumvented:
 *
 *   - **It owns no campaign rules.** Every figure comes from the customer's own read
 *     models ({@see CampaignOperatorSummary} composes {@see CampaignProgress},
 *     {@see CampaignSummary} and {@see CampaignInterruption}), and
 *     every state change goes through the same campaign methods the customer's own
 *     buttons call. An administrator has no more authority over a campaign's
 *     legality than its owner has.
 *   - **It cannot start, edit or reconfigure a campaign.** There is no route here
 *     that writes a snapshot, an audience, a transport or a schedule. Launching is a
 *     preflight decision the domain makes for a specific owner, and an operator who
 *     could press it on somebody's behalf would be sending mail that nobody checked.
 *   - **It offers no workaround for a failing transport.** There is no "send through
 *     another account" button, no rotation, and no way past a refusal. An operator
 *     can read what failed, stop what is failing, and fix the account — which is what
 *     {@see CampaignStatus::Failed} exists to require.
 */
class CampaignController extends Controller
{
    public function index(Request $request, CampaignOperatorIndex $index): View
    {
        $filters = CampaignOperatorFilters::fromRequest($request);

        return view('admin.campaigns.index', [
            'campaigns' => $index->paginate($filters),
            'filters' => $filters,
            'statuses' => CampaignStatus::cases(),
            'statusCounts' => $index->statusCountsFor(),
            'owners' => $index->owners(),
            'transports' => $index->transports(),
            'activities' => CampaignOperatorFilters::activities(),
            'incidents' => $index->incidents(),
            'incidentCount' => $index->incidentCount(),
            'sendingNow' => $index->sendingNow(),
        ]);
    }

    /**
     * One campaign, as an operator.
     *
     * Deliberately not the tenant-ownership check the customer's page uses: an
     * operator is meant to read across tenants, and the authorization for that is
     * the `campaigns.view` permission on the route. The record still has to exist,
     * so an unknown id is a 404 rather than an empty operator view.
     */
    public function show(mixed $campaign): View
    {
        $record = $this->find($campaign);

        return view('admin.campaigns.show', [
            'detail' => CampaignOperatorDetail::read($record),
            'interruption' => CampaignInterruption::for($record),
            'recipientStatuses' => CampaignRecipientStatus::cases(),
        ]);
    }

    /**
     * What cancelling would cost, before it is done.
     *
     * The customer's route does this for the same reason and the same reason applies
     * here: cancelling is the one action on a campaign that cannot be undone, and an
     * operator moving one campaign is one click away from stopping somebody else's
     * send to eleven thousand people.
     */
    public function confirmCancel(mixed $campaign): View
    {
        $record = $this->find($campaign);

        abort_if(! $record->status->allowsCancel(), 409);

        $counts = $record->recipientCounts();

        return view('admin.campaigns.cancel', [
            'campaign' => $record,
            'sent' => $counts[CampaignRecipientStatus::Sent->value] ?? 0,
            'outstanding' => $record->remainingCount(),
            'owner' => $record->user,
        ]);
    }

    public function pause(mixed $campaign): RedirectResponse
    {
        $record = $this->find($campaign);

        // The domain is asked whether this is legal, here, on the server. What the
        // page offered is a claim about the state a moment ago.
        abort_if(! $record->status->allowsPause(), 409);

        $record->pause();

        return redirect()->route('admin.campaigns.show', $record)
            ->with('status', 'Campaign paused. Messages already accepted are not recalled.');
    }

    public function resume(mixed $campaign): RedirectResponse
    {
        $record = $this->find($campaign);

        abort_if(! $record->status->allowsResume(), 409);

        $record->resume();

        // Queued rather than run inline, exactly as the customer's own resume does:
        // pacing belongs to the worker, not to a web request.
        ProcessCampaignJob::dispatch($record->id);

        return redirect()->route('admin.campaigns.show', $record)
            ->with('status', 'Campaign resumed. Sending resumes when the worker next runs.');
    }

    public function cancel(mixed $campaign): RedirectResponse
    {
        $record = $this->find($campaign);

        abort_if(! $record->status->allowsCancel(), 409);

        $record->cancel();

        return redirect()->route('admin.campaigns.show', $record)
            ->with('status', 'Campaign cancelled. Nothing further will be sent from it.');
    }

    /**
     * The campaign itself, or a 404.
     *
     * Ownership is not checked here on purpose — see {@see self::show()} — but the
     * identifier still has to resolve, and it has to resolve to one record rather
     * than to a route that silently renders an empty operator view.
     */
    private function find(mixed $campaign): Campaign
    {
        abort_if(! is_numeric((string) $campaign), 404);

        $record = Campaign::query()->find((int) $campaign);

        abort_if($record === null, 404);

        return $record;
    }
}

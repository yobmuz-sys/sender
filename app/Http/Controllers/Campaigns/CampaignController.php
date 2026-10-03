<?php

declare(strict_types=1);

namespace App\Http\Controllers\Campaigns;

use App\Domain\Campaigns\AudienceSnapshot;
use App\Domain\Campaigns\Campaign;
use App\Domain\Campaigns\CampaignIndex;
use App\Domain\Campaigns\CampaignIndexFilters;
use App\Domain\Campaigns\CampaignLauncher;
use App\Domain\Campaigns\CampaignPreflight;
use App\Domain\Campaigns\CampaignStatus;
use App\Domain\Campaigns\CampaignSummary;
use App\Domain\Mail\SmtpAccount;
use App\Domain\Templates\Template;
use App\Http\Controllers\Controller;
use App\Http\Requests\Campaigns\StoreCampaignRequest;
use App\Jobs\ProcessCampaignJob;
use App\Models\ContactList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * A tenant's campaigns: prepare one, launch it, watch it, stop it.
 *
 * Four ideas carry the whole controller:
 *
 *   1. **Preparation and sending are different screens.** Creating a campaign
 *      records intent; starting one freezes it. Nothing is sent from a form
 *      submission, and no button anywhere sends a single message to a single
 *      recipient on a request's say-so.
 *   2. **The preflight is a service, not a page.** This controller renders what
 *      {@see CampaignPreflight} answers and the start action asks it again on the
 *      server. A result displayed in a browser is a claim; this one is a decision.
 *   3. **Ownership is scoped, and another tenant's campaign is a 404.** Campaign ids
 *      are sequential, so a 403 would confirm the record exists.
 *   4. **Every state change is a POST.** Starting, pausing, resuming and cancelling
 *      each put mail on the wire or stop it, and none of them is reachable by
 *      following a link.
 *
 * Editing is only offered while a campaign is still editable. A running campaign's
 * message is frozen in the database, and a form that appeared to change it would be
 * worse than one that is honestly absent.
 */
class CampaignController extends Controller
{
    /**
     * Recipients listed on the campaign page per page.
     */
    private const RECIPIENTS_PER_PAGE = 50;

    /**
     * The campaign list.
     *
     * Deliberately does almost nothing itself. It asks {@see CampaignIndex} for a
     * page, for the state counts and for the transports to filter by, and hands
     * them to a view. Eligibility, suppression, preflight and state transitions
     * belong to the domain types that already own them, and a list page that
     * restated any of them would be a second copy that could disagree with the
     * page that actually decides whether a campaign may send.
     *
     * Ownership is applied by the query, before anything is counted, so there is no
     * version of this page that can see another tenant's campaigns.
     */
    public function index(Request $request, CampaignIndex $index): View
    {
        $filters = CampaignIndexFilters::fromRequest($request);
        $campaigns = $index->paginateFor($request->user(), $filters);

        return view('campaigns.index', [
            'campaigns' => $campaigns,
            'filters' => $filters,
            'statuses' => CampaignStatus::cases(),
            'accounts' => $index->accountsFor($request->user()),
            'statusCounts' => $index->statusCountsFor($request->user()),
        ]);
    }

    /**
     * Ask before cancelling.
     *
     * Cancelling cannot be undone, so it is not a button on a list of twenty-five
     * rows. This page says what stopping means for *this* campaign — how many
     * messages have already gone out and will not be recalled, and how many are
     * still waiting — and only here is there a button that does it.
     */
    public function confirmCancel(Request $request, mixed $campaign = null): View
    {
        $record = $this->owned($request, $campaign);

        abort_if(! $record->status->allowsCancel(), 409);

        return view('campaigns.cancel', [
            'campaign' => $record,
            'counts' => $record->recipientCounts(),
        ]);
    }

    /**
     * The builder.
     *
     * Renders an unsaved campaign so the preflight can be answered for whatever is
     * currently selected, in a query string or a draft, and shows the audience
     * consequences of the chosen list before anything exists.
     */
    public function create(Request $request): View
    {
        $campaign = new Campaign(['user_id' => $request->user()->id]);

        // The form's current selections arrive as query parameters, which is how
        // the builder refreshes its preflight panel when a choice changes without
        // a JavaScript framework and without saving anything first.
        $campaign->name = (string) $request->query('name', '');
        $campaign->template_id = $this->selected($request, 'template_id');
        $campaign->list_id = $this->selected($request, 'list_id');
        $campaign->smtp_account_id = $this->selected($request, 'smtp_account_id');
        $campaign->rate_interval_seconds = (int) $request->query(
            'rate_interval_seconds',
            (int) config('sender.sending.minimum_interval_seconds', 30),
        );
        $campaign->worker_batch_size = (int) $request->query('worker_batch_size', 10);

        return view('campaigns.create', [
            'campaign' => $campaign,
            'preflight' => app(CampaignPreflight::class),
            'audience' => app(AudienceSnapshot::class),
            'templates' => $this->templates($request),
            'lists' => $this->lists($request),
            'accounts' => $this->accounts($request),
        ]);
    }

    /**
     * A selected identifier from the query string, or null.
     */
    private function selected(Request $request, string $key): ?int
    {
        $value = $request->query($key);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Save a draft, or start one.
     *
     * A single form with two outcomes, because "start" is not a different kind of
     * campaign — it is this campaign, one step further. The start path re-runs the
     * preflight server-side and refuses with the report if anything has changed
     * since the page was rendered, which is the whole reason the button is not
     * trusted.
     */
    public function store(StoreCampaignRequest $request): RedirectResponse
    {
        $campaign = new Campaign(['user_id' => $request->user()->id]);

        $campaign->fill($request->safe()->only([
            'name',
            'template_id',
            'list_id',
            'smtp_account_id',
            'rate_interval_seconds',
            'worker_batch_size',
        ]));

        $campaign->scheduled_at = $request->validated('scheduled_at');
        $campaign->scheduled_timezone = $request->validated('scheduled_timezone');
        $campaign->save();

        if (! $request->boolean('start')) {
            return redirect()->route('campaigns.show', $campaign)
                ->with('status', 'Campaign saved as a draft. Nothing has been sent.');
        }

        return $this->startCampaign($campaign);
    }

    public function show(Request $request, CampaignPreflight $preflight, mixed $campaign = null): View
    {
        $record = $this->owned($request, $campaign);

        $counts = $record->recipientCounts();

        return view('campaigns.show', [
            'campaign' => $record,
            'preflight' => $preflight,

            // The same read model the list builds, from the counts this page has
            // already fetched, so the action buttons here and in the list are
            // decided by one piece of code.
            'summary' => CampaignSummary::of($record, $counts),

            // Answered whatever the state, not only once launched. A draft's
            // blockers are the most useful thing on the page — they are why the
            // start button is unavailable — and a launched campaign's are shown
            // again so it is obvious that later changes to a template or a
            // transport do not alter what is already going out.
            'report' => $preflight->reportFor($record),
            'counts' => $counts,
            'recipients' => $record->recipients()
                ->with('contact')
                ->orderBy('id')
                ->paginate(self::RECIPIENTS_PER_PAGE),
            'lastFailure' => $record->recipients()
                ->whereNotNull('last_attempt_at')
                ->orderByDesc('last_attempt_at')
                ->first(),
        ]);
    }

    public function edit(Request $request, mixed $campaign = null): View
    {
        $record = $this->owned($request, $campaign);

        // A launched campaign's configuration is frozen. Answering with the page
        // is more honest than answering with a form whose changes are ignored.
        abort_if(! $record->isConfigurable(), 409);

        return view('campaigns.edit', [
            'campaign' => $record,
            'preflight' => app(CampaignPreflight::class),
            'audience' => app(AudienceSnapshot::class),
            'templates' => $this->templates($request),
            'lists' => $this->lists($request),
            'accounts' => $this->accounts($request),
        ]);
    }

    public function update(StoreCampaignRequest $request, mixed $campaign = null): RedirectResponse
    {
        $record = $this->owned($request, $campaign);

        abort_if(! $record->isConfigurable(), 409);

        $record->fill($request->safe()->only([
            'name',
            'template_id',
            'list_id',
            'smtp_account_id',
            'rate_interval_seconds',
            'worker_batch_size',
        ]));

        $record->scheduled_at = $request->validated('scheduled_at');
        $record->scheduled_timezone = $request->validated('scheduled_timezone');

        // A scheduled campaign that is edited has not launched, so it still has
        // no snapshot and no recipients. Clearing them is a no-op then, and
        // prevents a stale snapshot from surviving a reconfiguration.
        if ($record->status === CampaignStatus::Scheduled) {
            $record->status = CampaignStatus::Draft;
            $record->scheduled_at = null;
            $record->scheduled_timezone = null;
        }

        $record->save();

        return redirect()->route('campaigns.show', $record)
            ->with('status', 'Campaign updated.');
    }

    /**
     * Start sending.
     *
     * The launcher is asked again, here, on the server. Whatever the page said when
     * it was rendered is a claim; this is the decision, and it is made from the
     * campaign's own state a moment ago.
     */
    public function start(Request $request, CampaignLauncher $launcher, mixed $campaign = null): RedirectResponse
    {
        $record = $this->owned($request, $campaign);

        abort_if(! $record->status->allowsStart(), 409);

        return $this->startCampaign($record, $launcher);
    }

    /**
     * Send now instead of at the scheduled time.
     *
     * The one action that overrules a plan the customer made, so it is offered
     * only while the campaign is still waiting — a running campaign has no schedule
     * left to overrule, and a cancelled one has nothing to send. Everything else is
     * unchanged: the same launcher, the same server-side preflight, the same job
     * handed to the queue rather than a request that sends inline.
     */
    public function sendNow(Request $request, CampaignLauncher $launcher, mixed $campaign = null): RedirectResponse
    {
        $record = $this->owned($request, $campaign);

        abort_if(! $record->status->allowsStart(), 409);
        abort_if($record->scheduled_at === null || ! $record->scheduled_at->isFuture(), 409);

        return $this->startCampaign($record, $launcher, immediately: true);
    }

    public function pause(Request $request, mixed $campaign = null): RedirectResponse
    {
        $record = $this->owned($request, $campaign);

        abort_if(! $record->status->allowsPause(), 409);

        $record->pause();

        return redirect()->route('campaigns.show', $record)
            ->with('status', 'Campaign paused. Messages already sent are not recalled.');
    }

    public function resume(Request $request, mixed $campaign = null): RedirectResponse
    {
        $record = $this->owned($request, $campaign);

        abort_if(! $record->status->allowsResume(), 409);

        $record->resume();

        // Handed to the queue rather than run inline: sending from a web request
        // would put a message on the wire during somebody's page load, and would
        // tie one campaign's pacing to one PHP process's lifetime.
        ProcessCampaignJob::dispatch($record->id);

        return redirect()->route('campaigns.show', $record)
            ->with('status', 'Campaign resumed. Sending resumes when the worker next runs.');
    }

    public function cancel(Request $request, mixed $campaign = null): RedirectResponse
    {
        $record = $this->owned($request, $campaign);

        abort_if(! $record->status->allowsCancel(), 409);

        $record->cancel();

        return redirect()->route('campaigns.show', $record)
            ->with('status', 'Campaign cancelled. Nothing further will be sent from it.');
    }

    /**
     * Shared by the "start" button on the builder and the one on the detail page.
     *
     * Both paths end here, so both get the same server-side preflight and the same
     * refusal message — a campaign created with "start now" ticked is not a
     * weaker version of one started afterwards.
     *
     * `$immediately` is the one thing that differs, and it differs only in what the
     * launcher does with the schedule: everything else, including the claim that it
     * sends, is decided by the launcher and the worker rather than here.
     */
    private function startCampaign(
        Campaign $campaign,
        ?CampaignLauncher $launcher = null,
        bool $immediately = false,
    ): RedirectResponse {
        $launcher ??= app(CampaignLauncher::class);

        $refused = $immediately
            ? $launcher->launchNow($campaign)
            : $launcher->launch($campaign);

        if ($refused !== null) {
            return redirect()->route('campaigns.show', $campaign)
                ->with('error', $refused->summary());
        }

        ProcessCampaignJob::dispatch($campaign->id);

        $local = $campaign->scheduledLocalTime();

        return redirect()->route('campaigns.show', $campaign)
            ->with('status', $immediately
                ? 'Campaign started now. The scheduled start time no longer applies.'
                : ($local !== null && $campaign->scheduled_at?->isFuture()
                    ? 'Campaign scheduled. It will begin when the worker next runs after '
                        .$local->format('j M Y \a\t H:i').' '.$campaign->scheduledTimezone().'.'
                    : 'Campaign started. Sending happens when the worker runs, spaced by the interval you set.'));
    }

    /**
     * @return Collection<int, Template>
     */
    private function templates(Request $request)
    {
        return Template::query()
            ->ownedBy((int) $request->user()->id)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, ContactList>
     */
    private function lists(Request $request)
    {
        return ContactList::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, SmtpAccount>
     */
    private function accounts(Request $request)
    {
        return SmtpAccount::query()
            ->ownedBy((int) $request->user()->id)
            ->orderBy('label')
            ->get();
    }

    /**
     * A campaign belonging to this account, or 404.
     */
    private function owned(Request $request, mixed $campaign): Campaign
    {
        if (! is_numeric((string) $campaign)) {
            abort(404);
        }

        $record = Campaign::query()->find((int) $campaign);

        if ($record === null || (int) $record->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        return $record;
    }
}

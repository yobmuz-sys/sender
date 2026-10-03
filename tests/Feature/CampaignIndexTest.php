<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Audience\AudienceEligibility;
use App\Domain\Campaigns\Campaign;
use App\Domain\Campaigns\CampaignAction;
use App\Domain\Campaigns\CampaignIndex;
use App\Domain\Campaigns\CampaignIndexFilters;
use App\Domain\Campaigns\CampaignLauncher;
use App\Domain\Campaigns\CampaignRecipient;
use App\Domain\Campaigns\CampaignRecipientStatus;
use App\Domain\Campaigns\CampaignStatus;
use App\Domain\Campaigns\CampaignSummary;
use App\Models\User;
use App\Support\Navigation\ProductNavigation;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * The campaign list: the control centre a customer opens to answer "where is my
 * campaign, and what has happened to it".
 *
 * The properties worth protecting here are narrower than they look. This page has
 * no rules in it — it cannot decide anything about sending — so what it must get
 * right is arithmetic and isolation: the figures must be counts of recipient rows
 * that exist, the progress must be the same number this domain calls finished, the
 * actions must be the ones the campaign's own page would accept, and none of it may
 * ever see another tenant's rows.
 */
class CampaignIndexTest extends CampaignTestCase
{
    public function test_a_tenant_sees_only_their_own_campaigns(): void
    {
        $user = $this->signedInTenant();
        $stranger = $this->signedInTenant();

        $this->draftFor($user, ['name' => 'My October update']);
        $this->draftFor($stranger, ['name' => 'Somebody elses campaign']);

        $this->actingAs($user)
            ->get(route('campaigns.index'))
            ->assertOk()
            ->assertSee('My October update')
            ->assertDontSee('Somebody elses campaign');
    }

    public function test_another_tenants_campaigns_are_not_even_counted(): void
    {
        $user = $this->signedInTenant();
        $stranger = $this->signedInTenant();

        $this->draftFor($user, ['name' => 'Mine']);
        $this->draftFor($stranger, ['name' => 'Theirs']);
        $this->draftFor($stranger, ['name' => 'Also theirs']);

        $body = $this->actingAs($user)
            ->get(route('campaigns.index', ['status' => CampaignStatus::Draft->value]))
            ->assertOk()
            ->getContent();

        $this->assertIsString($body);
        $this->assertStringContainsString('out of 1 in total', $body);
        $this->assertStringNotContainsString('Theirs', $body);
    }

    public function test_an_account_with_no_campaigns_is_told_what_to_do(): void
    {
        $user = $this->signedInTenant();

        $this->actingAs($user)
            ->get(route('campaigns.index'))
            ->assertOk()
            ->assertSee('No campaigns yet')
            ->assertSee('a ready template, a verified sending account')
            ->assertSee('New campaign');
    }

    public function test_the_empty_state_does_not_claim_anything_was_sent(): void
    {
        $user = $this->signedInTenant();

        $body = $this->actingAs($user)
            ->get(route('campaigns.index'))
            ->assertOk()
            ->getContent();

        $this->assertIsString($body);
        $this->assertStringNotContainsString('0%', $body);
        $this->assertStringNotContainsString('delivered', strtolower($body));
    }

    public function test_every_state_is_named_with_its_own_label(): void
    {
        $user = $this->signedInTenant();
        $list = $this->listFor($user);

        $states = [
            CampaignStatus::Draft,
            CampaignStatus::Scheduled,
            CampaignStatus::Paused,
            CampaignStatus::Cancelled,
        ];

        foreach ($states as $state) {
            $this->draftFor($user, [
                'list_id' => $list->id,
                'status' => $state->value,
                'name' => $state->value.' campaign',
            ]);
        }

        $running = $this->launchedCampaign($user, ['list_id' => $list->id, 'name' => 'running campaign']);
        $this->actingAs($user)->post(route('campaigns.pause', $running));

        $this->actingAs($user)
            ->get(route('campaigns.index'))
            ->assertOk()
            ->assertSee('Draft')
            ->assertSee('Scheduled')
            ->assertSee('Paused')
            ->assertSee('Cancelled');
    }

    public function test_a_running_campaign_is_named_sending_and_a_draft_is_named_draft(): void
    {
        $user = $this->signedInTenant();

        $this->draftFor($user, ['name' => 'not started yet']);

        $running = $this->launchedCampaign($user, ['name' => 'Product launch']);
        $running->forceFill([
            'status' => CampaignStatus::Running->value,
            'last_activity_at' => now(),
        ])->save();

        $body = $this->actingAs($user)->get(route('campaigns.index'))->assertOk()->getContent();

        $this->assertIsString($body);
        $this->assertStringContainsString('Product launch', $body);

        // The domain's own label, not a word invented for this page.
        $this->assertStringContainsString(CampaignStatus::Running->label(), $body);
        $this->assertStringContainsString(CampaignStatus::Draft->label(), $body);
    }

    public function test_the_figures_are_counts_of_the_recipient_rows_that_exist(): void
    {
        $user = $this->signedInTenant();
        $campaign = $this->launchedCampaign($user, ['name' => 'October update']);

        $this->withRecipients($campaign, [
            CampaignRecipientStatus::Sent->value => 4,
            CampaignRecipientStatus::Failed->value => 2,
            CampaignRecipientStatus::Skipped->value => 1,
            CampaignRecipientStatus::Queued->value => 3,
        ]);

        $summary = $this->firstSummary($user);

        $this->assertSame(10, $summary->progress->total());
        $this->assertSame(4, $summary->sentCount());
        $this->assertSame(2, $summary->progress->count(CampaignRecipientStatus::Failed));
        $this->assertSame(1, $summary->progress->count(CampaignRecipientStatus::Skipped));
        $this->assertSame(3, $summary->progress->remaining());
    }

    public function test_progress_counts_every_dealt_with_recipient_and_not_only_the_sent_ones(): void
    {
        $user = $this->signedInTenant();
        $campaign = $this->launchedCampaign($user);

        // 7 of 10 dealt with, of which only 4 were sent. A bar that counted
        // successful sends would read 40% and tell the customer there was most of
        // a campaign still to go when the worker had nothing left to send.
        $this->withRecipients($campaign, [
            CampaignRecipientStatus::Sent->value => 4,
            CampaignRecipientStatus::Failed->value => 1,
            CampaignRecipientStatus::Skipped->value => 1,
            CampaignRecipientStatus::Blocked->value => 1,
            CampaignRecipientStatus::Queued->value => 3,
        ]);

        $summary = $this->firstSummary($user);

        $this->assertSame(7, $summary->progress->settled());
        $this->assertSame(70, $summary->progress->percent());
    }

    public function test_a_draft_has_no_percentage_because_it_has_no_audience_yet(): void
    {
        $user = $this->signedInTenant();
        $this->draftFor($user, ['name' => 'Not started yet']);

        $summary = $this->firstSummary($user);

        $this->assertNull($summary->progress->percent());
        $this->assertNull($summary->audienceCount());
        $this->assertSame(0, $summary->sentCount());
        $this->assertSame('Not started', $summary->progress->label()['title']);
    }

    public function test_the_list_renders_the_figures_and_the_percentage(): void
    {
        $user = $this->signedInTenant();
        $campaign = $this->launchedCampaign($user, ['name' => 'August newsletter']);

        // Six of ten dealt with: five sent, one refused by the server.
        $this->withRecipients($campaign, [
            CampaignRecipientStatus::Sent->value => 5,
            CampaignRecipientStatus::Failed->value => 1,
            CampaignRecipientStatus::Queued->value => 4,
        ]);

        $body = $this->actingAs($user)->get(route('campaigns.index'))->assertOk()->getContent();

        $this->assertIsString($body);
        $this->assertStringContainsString('August newsletter', $body);
        $this->assertStringContainsString('60%', $body);
        $this->assertStringContainsString('6 of 10 dealt with', $body);
        $this->assertStringContainsString('1 failed', $body);
    }

    public function test_actions_depend_on_the_state_of_the_campaign(): void
    {
        $user = $this->signedInTenant();
        $list = $this->listFor($user);
        $this->eligibleContact($user, $list);

        $draft = $this->draftFor($user, ['list_id' => $list->id, 'name' => 'draft campaign']);
        $this->assertSame(
            ['open', 'edit'],
            array_map(fn ($action) => $action->value, $this->actionsFor($user, $draft)),
        );

        $running = $this->launchedCampaign($user, [
            'list_id' => $list->id,
            'name' => 'running campaign',
            'status' => CampaignStatus::Running->value,
        ]);
        $this->assertSame(
            ['open', 'pause', 'cancel'],
            array_map(fn ($action) => $action->value, $this->actionsFor($user, $running)),
        );

        $running->pause();
        $this->assertSame(
            ['open', 'resume', 'cancel'],
            array_map(fn ($action) => $action->value, $this->actionsFor($user, $running->fresh())),
        );

        $running->resume();
        $running->cancel();
        $this->assertSame(
            ['open'],
            array_map(fn ($action) => $action->value, $this->actionsFor($user, $running->fresh())),
        );
    }

    public function test_a_waiting_campaign_cannot_be_paused_because_it_has_not_started_sending(): void
    {
        $user = $this->signedInTenant();
        $list = $this->listFor($user);
        $this->eligibleContact($user, $list);

        // Launched, frozen, and waiting for its start time: the state the launcher
        // leaves a campaign in when the customer chose a future moment.
        $scheduled = $this->draftFor($user, [
            'list_id' => $list->id,
            'name' => 'waiting campaign',
            'scheduled_at' => now()->addWeek(),
        ]);

        $this->assertNull(app(CampaignLauncher::class)->launch($scheduled));
        $scheduled = $scheduled->fresh();

        $this->assertSame(CampaignStatus::Scheduled, $scheduled->status);

        // Offered: open, edit and cancel. Not offered: pause, because pausing
        // something that has sent nothing is cancelling it under a name that
        // suggests it can be undone.
        $this->assertSame(
            ['open', 'edit', 'cancel'],
            array_map(fn (CampaignAction $action) => $action->value, $this->actionsFor($user, $scheduled)),
        );
    }

    public function test_a_draft_is_not_offered_a_cancel_that_its_own_page_would_not_need(): void
    {
        $user = $this->signedInTenant();
        $draft = $this->draftFor($user, ['name' => 'barely started']);

        $this->assertSame(
            ['open', 'edit'],
            array_map(fn (CampaignAction $action) => $action->value, $this->actionsFor($user, $draft)),
        );
    }

    public function test_cancelling_from_the_list_asks_first_and_then_does_it(): void
    {
        $user = $this->signedInTenant();
        $campaign = $this->launchedCampaign($user, [
            'name' => 'to be cancelled',
            'status' => CampaignStatus::Running->value,
        ]);

        $this->withRecipients($campaign, [CampaignRecipientStatus::Sent->value => 2, CampaignRecipientStatus::Queued->value => 5]);

        $this->actingAs($user)
            ->get(route('campaigns.confirmCancel', $campaign))
            ->assertOk()
            ->assertSee('This cannot be undone')
            ->assertSee('Cancel this campaign');

        // Reading that page changes nothing.
        $this->assertSame(CampaignStatus::Running, $campaign->fresh()->status);

        $this->actingAs($user)->post(route('campaigns.cancel', $campaign))->assertRedirect();

        $this->assertSame(CampaignStatus::Cancelled, $campaign->fresh()->status);
    }

    public function test_a_campaign_that_cannot_be_cancelled_offers_nothing_to_confirm(): void
    {
        $user = $this->signedInTenant();

        $campaign = $this->launchedCampaign($user);
        $campaign->forceFill(['status' => CampaignStatus::Completed->value])->save();

        $this->actingAs($user)
            ->get(route('campaigns.confirmCancel', $campaign))
            ->assertStatus(409);
    }

    public function test_the_page_is_sorted_by_what_needs_attention_first(): void
    {
        $user = $this->signedInTenant();

        $this->draftFor($user, ['name' => 'an old draft']);

        // Statuses set after the launch, because the launcher's job is to put a
        // campaign into the state the worker finds it in — a factory that sets
        // "completed" beforehand would be overwritten, which is the point.
        $finished = $this->launchedCampaign($user, ['name' => 'a finished one']);
        $finished->forceFill([
            'status' => CampaignStatus::Completed->value,
            'last_activity_at' => now()->subMonth(),
        ])->save();

        $sending = $this->launchedCampaign($user, ['name' => 'the one that is sending']);
        $sending->forceFill([
            'status' => CampaignStatus::Running->value,
            'last_activity_at' => now(),
        ])->save();

        $order = $this->campaignNames($user);

        $this->assertSame(
            ['the one that is sending', 'an old draft', 'a finished one'],
            $order,
            'Sending first, then what nobody has started, then work that is over.',
        );
    }

    public function test_the_list_paginates(): void
    {
        $user = $this->signedInTenant();

        foreach (range(1, 26) as $index) {
            $this->draftFor($user, ['name' => 'campaign '.$index]);
        }

        $first = $this->actingAs($user)->get(route('campaigns.index'))->assertOk();
        $firstPage = $first->viewData('campaigns');

        $this->assertSame(26, $firstPage->total());
        $this->assertSame(25, $firstPage->count());
        $this->assertSame('campaign 26', $firstPage->first()->campaign->name, 'Newest first.');

        $second = $this->actingAs($user)->get(route('campaigns.index', ['page' => 2]))->assertOk();
        $secondPage = $second->viewData('campaigns');

        $this->assertSame(1, $secondPage->count());
        $this->assertSame('campaign 1', $secondPage->first()->campaign->name);
        $second->assertSee('campaign 1');
    }

    public function test_search_finds_a_campaign_by_its_frozen_subject(): void
    {
        $user = $this->signedInTenant();

        $this->draftFor($user, ['name' => 'internal reference 4']);
        $sent = $this->launchedCampaign($user, ['name' => 'internal reference 5']);
        $sent->forceFill(['subject_snapshot' => 'Your October statement is ready'])->save();

        $this->draftFor($user, ['name' => 'unrelated draft']);

        $this->actingAs($user)
            ->get(route('campaigns.index', ['search' => 'October statement']))
            ->assertOk()
            ->assertSee('internal reference 5')
            ->assertDontSee('internal reference 4')
            ->assertDontSee('unrelated draft');
    }

    public function test_search_treats_wildcards_as_text(): void
    {
        $user = $this->signedInTenant();

        $this->draftFor($user, ['name' => 'percentage campaign']);
        $this->draftFor($user, ['name' => 'another campaign']);

        $this->actingAs($user)
            ->get(route('campaigns.index', ['search' => '%']))
            ->assertOk()
            ->assertSee('No campaigns match')
            ->assertDontSee('percentage campaign');
    }

    public function test_a_status_filter_narrows_the_list_and_the_header_still_counts_everything(): void
    {
        $user = $this->signedInTenant();

        $this->draftFor($user, ['name' => 'a draft one']);
        $this->draftFor($user, ['name' => 'another draft']);

        $this->actingAs($user)
            ->get(route('campaigns.index', ['status' => CampaignStatus::Draft->value]))
            ->assertOk()
            ->assertSee('a draft one')
            ->assertSee('2 campaigns match')
            ->assertSee('out of 2 in total');
    }

    public function test_a_status_that_does_not_exist_is_ignored_rather_than_matching_nothing(): void
    {
        $user = $this->signedInTenant();
        $this->draftFor($user, ['name' => 'still here']);

        $this->actingAs($user)
            ->get(route('campaigns.index', ['status' => 'half-done']))
            ->assertOk()
            ->assertSee('still here');
    }

    public function test_the_transport_filter_uses_the_customer_s_own_accounts_only(): void
    {
        $user = $this->signedInTenant();
        $stranger = $this->signedInTenant();

        $mine = $this->verifiedAccountFor($user, ['label' => 'my sending account']);
        $theirs = $this->verifiedAccountFor($stranger, ['label' => 'their sending account']);

        $this->draftFor($user, ['name' => 'sent through mine', 'smtp_account_id' => $mine->id]);
        $this->draftFor($user, ['name' => 'sent through theirs', 'smtp_account_id' => $theirs->id]);

        $this->actingAs($user)
            ->get(route('campaigns.index', ['smtp_account_id' => $mine->id]))
            ->assertOk()
            ->assertSee('sent through mine')
            ->assertDontSee('sent through theirs')
            ->assertSee('my sending account')
            ->assertDontSee('their sending account');
    }

    public function test_a_transport_id_from_another_tenant_is_not_even_offered_as_a_filter(): void
    {
        $user = $this->signedInTenant();
        $stranger = $this->signedInTenant();

        $theirs = $this->verifiedAccountFor($stranger, ['label' => 'their sending account']);
        $this->draftFor($user, ['name' => 'mine alone']);

        $this->actingAs($user)
            ->get(route('campaigns.index', ['smtp_account_id' => $theirs->id]))
            ->assertOk()
            ->assertSee('mine alone')
            ->assertDontSee('their sending account');
    }

    public function test_the_page_costs_the_same_however_many_campaigns_there_are(): void
    {
        $user = $this->signedInTenant();

        foreach (range(1, 2) as $index) {
            $this->launchedCampaign($user, ['name' => 'few '.$index]);
        }

        $withTwo = $this->countQueriesFor($user);

        foreach (range(3, 14) as $index) {
            $this->launchedCampaign($user, ['name' => 'many '.$index]);
        }

        $withFourteen = $this->countQueriesFor($user);

        // Twelve more campaigns, six more recipient rows between them, and the same
        // number of queries. The alternative — a count per row — would be twelve
        // more queries for the same screen.
        $this->assertSame($withTwo, $withFourteen);
    }

    /**
     * How many statements one render of the list costs.
     */
    private function countQueriesFor(User $user): int
    {
        $queries = 0;

        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->actingAs($user)->get(route('campaigns.index'))->assertOk();

        return $queries;
    }

    public function test_the_page_is_reachable_from_the_navigation_as_the_real_route(): void
    {
        $this->signedInTenant();

        $navigation = collect(ProductNavigation::items())->firstWhere('route', 'campaigns.index');

        $this->assertNotNull($navigation, 'The product navigation must point at the real campaign list.');
        $this->assertSame('campaigns.index', $navigation->route);
    }

    /**
     * A campaign frozen at launch, so the recipient rows it has are real.
     *
     * Goes through the launcher rather than a factory state, because a snapshot
     * written by hand proves nothing about whether the audience is built — and the
     * whole point of this page's figures is that they count rows the launcher made.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function launchedCampaign(User $user, array $overrides = []): Campaign
    {
        $campaign = $this->draftFor($user, $overrides);
        $list = $campaign->list;

        // The launcher refuses an audience of nobody, which is correct behaviour
        // and not what this helper is for. Measured with the same eligibility
        // query the preflight uses, so the helper cannot quietly disagree with it.
        if ($list !== null && app(AudienceEligibility::class)->countFor((int) $user->id, $list) === 0) {
            $this->eligibleContact($user, $list);
        }

        $refused = app(CampaignLauncher::class)->launchNow($campaign);

        $this->assertNull(
            $refused,
            'The campaign should have launched: '.collect($refused?->checks() ?? [])
                ->map(fn ($check) => $check->label.': '.$check->detail)
                ->implode(' | '),
        );

        return $campaign->fresh();
    }

    /**
     * Replace a campaign's frozen audience with exactly the given states.
     *
     * Deletes whatever the launcher built first, because a test about arithmetic
     * needs to know the exact denominator rather than the audience the launcher
     * happened to find — and because a figure "10 of 11" in a test that means "10
     * of 10" is a test that would pass for the wrong reason.
     *
     * @param  array<string, int>  $states  status value => how many
     */
    private function withRecipients(Campaign $campaign, array $states): void
    {
        $campaign->recipients()->delete();

        $email = 0;

        foreach ($states as $status => $count) {
            for ($i = 0; $i < $count; $i++) {
                $email++;

                CampaignRecipient::query()->create([
                    'campaign_id' => $campaign->id,
                    'contact_id' => null,
                    'email' => 'recipient'.$email.'@example.test',
                    'status' => $status,
                ]);
            }
        }
    }

    /**
     * The first row the list would render for this tenant.
     */
    private function firstSummary(User $user): CampaignSummary
    {
        $summary = $this->page($user)->first();

        $this->assertNotNull($summary, 'The list should have rendered a row.');

        return $summary;
    }

    /**
     * @return list<CampaignAction>
     */
    private function actionsFor(User $user, Campaign $campaign): array
    {
        return CampaignSummary::of($campaign, $campaign->recipientCounts())->actions();
    }

    /**
     * @return list<string>
     */
    private function campaignNames(User $user): array
    {
        return $this->page($user)->getCollection()
            ->map(fn (CampaignSummary $summary): string => $summary->campaign->name)
            ->all();
    }

    /**
     * The list page, built through the service rather than over HTTP.
     *
     * @return LengthAwarePaginator<int, CampaignSummary>
     */
    private function page(User $user, array $query = []): LengthAwarePaginator
    {
        $request = Request::create(route('campaigns.index', $query));
        $request->setUserResolver(fn (): User => $user);

        return app(CampaignIndex::class)->paginateFor($user, CampaignIndexFilters::fromRequest($request));
    }
}

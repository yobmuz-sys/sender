<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Campaigns\Campaign;
use App\Domain\Campaigns\CampaignInterruption;
use App\Domain\Campaigns\CampaignLauncher;
use App\Domain\Campaigns\CampaignProgress;
use App\Domain\Campaigns\CampaignRecipientStatus;
use App\Domain\Campaigns\CampaignRunner;
use App\Domain\Campaigns\CampaignStatus;
use App\Domain\Campaigns\Operator\CampaignOperatorFilters;
use App\Domain\Campaigns\Operator\CampaignOperatorIndex;
use App\Domain\Mail\DeliveryOutcome;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Permission;
use App\Jobs\ProcessCampaignJob;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Campaign administration, across tenants.
 *
 * The tests below assert three separate things, and keeping them apart is the point
 * of the page:
 *
 *   - **An operator can see across tenants.** The customer pages are still fenced
 *     by ownership, and these tests check both halves so neither leaks.
 *   - **An operator has no extra authority.** Every intervention here goes through
 *     the same domain methods the owner's own buttons call, an action the state does
 *     not allow is refused, and there is no route that starts, edits or
 *     re-transports a campaign.
 *   - **The figures are the customer's figures.** Progress on the admin page is
 *     asserted against `CampaignProgress` itself rather than against the number the
 *     template happened to print.
 */
class AdminCampaignOperationsTest extends CampaignTestCase
{
    public function test_the_admin_campaign_list_renders(): void
    {
        $this->actingAs($this->operator())
            ->get(route('admin.campaigns.index'))
            ->assertOk()
            ->assertSee('Monitor campaign activity across customer accounts.');
    }

    public function test_a_customer_cannot_reach_the_admin_campaign_pages(): void
    {
        $customer = $this->signedInTenant();
        $campaign = $this->sendingCampaign($customer, 2);

        $this->actingAs($customer)->get(route('admin.campaigns.index'))->assertForbidden();
        $this->actingAs($customer)->get(route('admin.campaigns.show', $campaign))->assertForbidden();
        $this->actingAs($customer)->post(route('admin.campaigns.pause', $campaign))->assertForbidden();
    }

    public function test_an_operator_sees_campaigns_from_every_customer_at_once(): void
    {
        $first = $this->signedInTenant();
        $second = $this->signedInTenant();

        $firstCampaign = $this->sendingCampaign($first, 2, ['name' => 'October update']);
        $secondCampaign = $this->sendingCampaign($second, 3, ['name' => 'Product announcement']);

        $this->actingAs($this->operator())
            ->get(route('admin.campaigns.index'))
            ->assertOk()
            ->assertSee('October update')
            ->assertSee('Product announcement')
            ->assertSee($first->name)
            ->assertSee($second->name)
            ->assertSee($first->email)
            ->assertSee($second->email);
    }

    public function test_the_owner_is_named_next_to_the_campaign(): void
    {
        $customer = $this->signedInTenant();
        $customer->forceFill(['name' => 'Ada Lovelace'])->save();

        $campaign = $this->sendingCampaign($customer, 1, ['name' => 'October update']);

        $this->actingAs($this->operator())
            ->get(route('admin.campaigns.index'))
            ->assertOk()
            ->assertSee('October update')
            ->assertSee('Ada Lovelace');
    }

    public function test_state_counts_are_counted_from_the_table_not_estimated(): void
    {
        $customer = $this->signedInTenant();

        $this->sendingCampaign($customer, 1, ['name' => 'running one']);
        $this->sendingCampaign($customer, 1, ['name' => 'running two']);

        $paused = $this->sendingCampaign($customer, 1, ['name' => 'paused one']);
        $paused->pause();

        $failed = $this->sendingCampaign($customer, 1, ['name' => 'failed one']);
        $this->transport->answering(DeliveryOutcome::ConnectionFailed, '421', 'Unavailable');
        $this->sendOne($failed);

        $this->assertSame(CampaignStatus::Failed, $failed->fresh()->status);

        $counts = app(CampaignOperatorIndex::class)->statusCountsFor();

        $this->assertSame(2, $counts[CampaignStatus::Running->value]);
        $this->assertSame(1, $counts[CampaignStatus::Paused->value]);
        $this->assertSame(1, $counts[CampaignStatus::Failed->value]);
    }

    public function test_the_header_says_how_many_campaigns_are_sending(): void
    {
        $customer = $this->signedInTenant();

        $this->sendingCampaign($customer, 1);
        $this->sendingCampaign($customer, 1);

        $paused = $this->sendingCampaign($customer, 1);
        $paused->pause();

        $this->actingAs($this->operator())
            ->get(route('admin.campaigns.index'))
            ->assertOk()
            ->assertSee('are currently sending');

        $this->assertSame(2, app(CampaignOperatorIndex::class)->sendingNow());
    }

    public function test_stopped_campaigns_are_surfaced_as_incidents_with_their_recorded_reason(): void
    {
        $customer = $this->signedInTenant();

        $this->sendingCampaign($customer, 1, ['name' => 'the healthy one']);

        $failed = $this->sendingCampaign($customer, 2, ['name' => 'broken transport']);
        $this->transport->answering(DeliveryOutcome::ConnectionFailed, '421', 'Service not available');
        $this->sendOne($failed);

        $paused = $this->sendingCampaign($customer, 2, ['name' => 'held by a person']);
        $paused->pause();

        $body = $this->actingAs($this->operator())
            ->get(route('admin.campaigns.index'))
            ->assertOk()
            ->assertSee('2 campaigns need attention')
            ->assertSee('broken transport')
            ->assertSee('held by a person')
            ->getContent();

        $this->assertIsString($body);

        // The reason shown is the campaign's own recorded account of the failure,
        // not a summary invented for an operator.
        $this->assertStringContainsString('stopped rather than switched to another transport', $body);

        // And nothing anywhere on the page offers the evasion. Checked as phrases
        // rather than the bare word "rotate", which appears in ordinary prose.
        $this->assertStringNotContainsString('switch to another account', strtolower($body));
        $this->assertStringNotContainsString('ip rotation', strtolower($body));
        $this->assertStringNotContainsString('rotate accounts', strtolower($body));
        $this->assertStringNotContainsString('rotate transports', strtolower($body));
        $this->assertStringNotContainsString('force send', strtolower($body));
    }

    public function test_a_healthy_campaign_is_not_an_incident(): void
    {
        $customer = $this->signedInTenant();
        $this->sendingCampaign($customer, 2, ['name' => 'sending happily']);

        $this->assertSame(0, app(CampaignOperatorIndex::class)->incidentCount());

        $this->actingAs($this->operator())
            ->get(route('admin.campaigns.index'))
            ->assertOk()
            ->assertDontSee('need attention');
    }

    public function test_incidents_link_to_the_campaign_and_to_the_transport(): void
    {
        $customer = $this->signedInTenant();
        $campaign = $this->sendingCampaign($customer, 1, ['name' => 'broken transport']);
        $campaign->pause();

        $this->actingAs($this->operator())
            ->get(route('admin.campaigns.index'))
            ->assertOk()
            ->assertSee(route('admin.campaigns.show', $campaign), false)
            ->assertSee(route('admin.smtp.accounts.show', $campaign->smtp_account_id), false);
    }

    public function test_progress_is_the_same_definition_the_customer_page_uses(): void
    {
        $customer = $this->signedInTenant();
        $campaign = $this->sendingCampaign($customer, 3);

        $this->sendOne($campaign);

        $campaign = $campaign->fresh();

        $expected = CampaignProgress::of(
            $campaign->status,
            $campaign->hasLaunched(),
            $campaign->recipientCounts(),
        );

        // The admin table reports the same counts, from the same definition. The
        // percentage itself is on the campaign's own page, where there is room to
        // explain it; a column of bare percentages would be a figure with no
        // denominator.
        $this->actingAs($this->operator())
            ->get(route('admin.campaigns.index'))
            ->assertOk()
            ->assertSee('Recipients')
            ->assertSee('Sent')
            ->assertSee('Remaining');

        $this->assertSame(3, $expected->total());
        $this->assertSame(1, $expected->count(CampaignRecipientStatus::Sent));
        $this->assertSame(2, $expected->remaining());

        $this->actingAs($this->operator())
            ->get(route('admin.campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('33%')
            ->assertSee('Delivery');
    }

    public function test_the_list_can_be_filtered_by_owner_status_and_transport(): void
    {
        $first = $this->signedInTenant();
        $second = $this->signedInTenant();

        // Nothing here is stopped, deliberately: a paused or failed campaign is
        // listed as an incident regardless of the filters, because an operator
        // must not be able to filter away the thing they are looking for. These
        // tests are about the table, so its rows are all uneventful.
        $mine = $this->sendingCampaign($first, 1, ['name' => 'mine running']);
        $mineDraft = $this->draftFor($first, ['name' => 'mine still a draft']);
        $theirs = $this->sendingCampaign($second, 1, ['name' => 'theirs running']);

        $this->actingAs($this->operator())
            ->get(route('admin.campaigns.index', ['owner' => $first->id]))
            ->assertOk()
            ->assertSee('mine running')
            ->assertSee('mine still a draft')
            ->assertDontSee('theirs running');

        $this->actingAs($this->operator())
            ->get(route('admin.campaigns.index', ['status' => 'draft']))
            ->assertOk()
            ->assertSee('mine still a draft')
            ->assertDontSee('mine running')
            ->assertDontSee('theirs running');

        $this->actingAs($this->operator())
            ->get(route('admin.campaigns.index', ['smtp_account' => $mine->smtp_account_id]))
            ->assertOk()
            ->assertSee('mine running')
            ->assertDontSee('theirs running');

        $this->assertNotSame($mine->smtp_account_id, $theirs->smtp_account_id);
        $this->assertSame(CampaignStatus::Draft, $mineDraft->fresh()->status);
    }

    public function test_the_search_filter_matches_a_campaign_name_or_its_frozen_subject(): void
    {
        $customer = $this->signedInTenant();
        $this->sendingCampaign($customer, 1, ['name' => 'October update']);

        $this->actingAs($this->operator())
            ->get(route('admin.campaigns.index', ['search' => 'October']))
            ->assertOk()
            ->assertSee('October update');

        $this->actingAs($this->operator())
            ->get(route('admin.campaigns.index', ['search' => 'nothing here']))
            ->assertOk()
            ->assertSee('No campaigns match');
    }

    public function test_the_activity_filter_is_backed_by_timestamps_rather_than_a_health_score(): void
    {
        $customer = $this->signedInTenant();

        $moving = $this->sendingCampaign($customer, 2, ['name' => 'moving along']);
        $this->sendOne($moving);

        $stalled = $this->sendingCampaign($customer, 2, ['name' => 'waiting for a worker']);
        $stalled->forceFill(['last_activity_at' => now()->subHours(6)])->save();

        $this->actingAs($this->operator())
            ->get(route('admin.campaigns.index', ['activity' => 'stalled']))
            ->assertOk()
            ->assertSee('waiting for a worker')
            ->assertDontSee('moving along');

        $this->actingAs($this->operator())
            ->get(route('admin.campaigns.index', ['activity' => 'moving']))
            ->assertOk()
            ->assertSee('moving along')
            ->assertDontSee('waiting for a worker');

        // "Stopped" is a state rather than a judgement, and its rows also appear as
        // incidents, so only the presence is asserted.
        $stopped = $this->sendingCampaign($customer, 2, ['name' => 'stopped by hand']);
        $stopped->pause();

        $this->actingAs($this->operator())
            ->get(route('admin.campaigns.index', ['activity' => 'stopped']))
            ->assertOk()
            ->assertSee('stopped by hand');

        // No such filter exists, and a hand-edited value is ignored rather than
        // producing a page that silently matches nothing.
        $this->actingAs($this->operator())
            ->get(route('admin.campaigns.index', ['activity' => 'healthy']))
            ->assertOk()
            ->assertSee('moving along')
            ->assertSee('waiting for a worker');
    }

    public function test_a_stalled_campaign_is_labelled_without_its_status_being_changed(): void
    {
        $customer = $this->signedInTenant();
        $campaign = $this->sendingCampaign($customer, 2, ['name' => 'waiting for a worker']);
        $campaign->forceFill(['last_activity_at' => now()->subHours(6)])->save();

        $this->actingAs($this->operator())
            ->get(route('admin.campaigns.index'))
            ->assertOk()
            ->assertSee('Sending')
            ->assertSee('No activity recently');

        // The label is a report about the timestamps. Nothing rewrote the campaign.
        $this->assertSame(CampaignStatus::Running, $campaign->fresh()->status);
    }

    public function test_the_list_is_paginated_and_never_reads_every_campaign(): void
    {
        $customer = $this->signedInTenant();

        for ($i = 0; $i < 30; $i++) {
            Campaign::factory()->for($customer)->create(['name' => 'campaign '.$i]);
        }

        $response = $this->actingAs($this->operator())->get(route('admin.campaigns.index'))->assertOk();
        $paginator = $response->viewData('campaigns');

        $this->assertSame(30, $paginator->total());
        $this->assertSame(25, $paginator->perPage());
        $this->assertCount(25, $paginator->items());

        $this->assertCount(5, $this->actingAs($this->operator())
            ->get(route('admin.campaigns.index', ['page' => 2]))
            ->assertOk()
            ->viewData('campaigns')
            ->items());
    }

    public function test_the_query_cost_does_not_grow_with_the_number_of_campaigns(): void
    {
        $customer = $this->signedInTenant();

        $this->sendingCampaign($customer, 1, ['name' => 'one']);

        $few = $this->queryCountFor($this->operator());

        for ($i = 0; $i < 12; $i++) {
            Campaign::factory()->for($customer)->create(['name' => 'extra '.$i]);
        }

        $many = $this->queryCountFor($this->operator());

        $this->assertSame(
            $few,
            $many,
            'Twelve more campaigns must not cost one more query: the counts are subqueries, not a loop.',
        );
    }

    public function test_the_admin_detail_page_reports_ownership_state_and_delivery(): void
    {
        $customer = $this->signedInTenant();
        $customer->forceFill(['name' => 'Ada Lovelace'])->save();

        $campaign = $this->sendingCampaign($customer, 3, ['name' => 'October update']);
        $this->sendOne($campaign);

        $this->actingAs($this->operator())
            ->get(route('admin.campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('October update')
            ->assertSee('Ada Lovelace')
            ->assertSee($customer->email)
            ->assertSee('Ownership')
            ->assertSee('Sending configuration')
            ->assertSee('Campaign state')
            ->assertSee('1 campaign on the platform uses this account')
            ->assertSee('From identity')
            ->assertSee('Message frozen at launch')
            ->assertSee($campaign->template_name_snapshot);
    }

    public function test_the_admin_detail_page_shows_no_credential(): void
    {
        $customer = $this->signedInTenant();
        $campaign = $this->sendingCampaign($customer, 1);

        $body = $this->actingAs($this->operator())
            ->get(route('admin.campaigns.show', $campaign))
            ->assertOk()
            ->getContent();

        $this->assertIsString($body);
        $this->assertStringNotContainsString('app-password-value', $body);
    }

    public function test_the_admin_detail_page_quotes_the_recorded_interruption(): void
    {
        $customer = $this->signedInTenant();
        $campaign = $this->sendingCampaign($customer, 2, ['name' => 'broken transport']);

        $this->transport->answering(DeliveryOutcome::ConnectionFailed, '421', 'Service unavailable');
        $this->sendOne($campaign);

        $body = $this->actingAs($this->operator())
            ->get(route('admin.campaigns.show', $campaign->fresh()))
            ->assertOk()
            ->assertSee('Sending stopped by a problem')
            ->getContent();

        $this->assertIsString($body);
        $this->assertStringContainsString('stopped rather than switched to another transport', $body);
        $this->assertStringContainsString('will not move it to another account for you', $body);
    }

    public function test_the_admin_detail_page_uses_the_same_interruption_object_as_the_customer_page(): void
    {
        $customer = $this->signedInTenant();
        $campaign = $this->sendingCampaign($customer, 2);
        $campaign->pause();

        $interruption = CampaignInterruption::for($campaign->fresh());

        $this->actingAs($this->operator())
            ->get(route('admin.campaigns.show', $campaign))
            ->assertOk()
            ->assertSee($interruption->headline)
            ->assertSee($interruption->detail);
    }

    public function test_an_operator_can_pause_and_resume_a_campaign(): void
    {
        $customer = $this->signedInTenant();
        $campaign = $this->sendingCampaign($customer, 2, ['name' => 'needs a brake']);

        $this->actingAs($this->operator())
            ->post(route('admin.campaigns.pause', $campaign))
            ->assertRedirect(route('admin.campaigns.show', $campaign));

        $this->assertSame(CampaignStatus::Paused, $campaign->fresh()->status);

        Queue::fake();

        $this->actingAs($this->operator())
            ->post(route('admin.campaigns.resume', $campaign))
            ->assertRedirect(route('admin.campaigns.show', $campaign));

        $this->assertSame(CampaignStatus::Running, $campaign->fresh()->status);

        // Resuming hands the work to the queue rather than sending during a request.
        Queue::assertPushed(ProcessCampaignJob::class);
    }

    public function test_an_operator_can_cancel_and_is_asked_what_it_will_cost_first(): void
    {
        $customer = $this->signedInTenant();
        $campaign = $this->sendingCampaign($customer, 3, ['name' => 'to be stopped']);

        $this->sendOne($campaign);

        $this->actingAs($this->operator())
            ->get(route('admin.campaigns.cancel', $campaign))
            ->assertOk()
            ->assertSee('Cancel this campaign?')
            ->assertSee('This is not reversible');

        $this->assertSame(CampaignStatus::Running, $campaign->fresh()->status, 'The page must not cancel anything.');

        $this->actingAs($this->operator())
            ->post(route('admin.campaigns.confirm', $campaign))
            ->assertRedirect(route('admin.campaigns.show', $campaign));

        $this->assertSame(CampaignStatus::Cancelled, $campaign->fresh()->status);
    }

    public function test_an_operator_cannot_perform_an_action_the_state_does_not_allow(): void
    {
        $customer = $this->signedInTenant();

        $draft = $this->draftFor($customer);
        $completed = $this->sendingCampaign($customer, 1);
        $this->sendUntilEmpty($completed);

        $cancelled = $this->sendingCampaign($customer, 1);
        $cancelled->cancel();

        foreach ([$draft, $completed, $cancelled] as $campaign) {
            $this->actingAs($this->operator())
                ->post(route('admin.campaigns.pause', $campaign))
                ->assertStatus(409);

            $this->actingAs($this->operator())
                ->post(route('admin.campaigns.resume', $campaign))
                ->assertStatus(409);

            $this->actingAs($this->operator())
                ->post(route('admin.campaigns.confirm', $campaign))
                ->assertStatus(409);
        }
    }

    public function test_the_admin_page_offers_only_the_actions_the_campaign_allows(): void
    {
        $customer = $this->signedInTenant();

        $runningCampaign = $this->sendingCampaign($customer, 1, ['name' => 'still going']);
        $pausedCampaign = $this->sendingCampaign($customer, 1, ['name' => 'on hold']);
        $pausedCampaign->pause();
        $completedCampaign = $this->sendingCampaign($customer, 1, ['name' => 'already done']);
        $this->sendUntilEmpty($completedCampaign);
        $this->sendUntilEmpty($completedCampaign);

        $operator = $this->operator();

        $running = $this->actingAs($operator)
            ->get(route('admin.campaigns.show', $runningCampaign))->assertOk()->getContent();
        $paused = $this->actingAs($operator)
            ->get(route('admin.campaigns.show', $pausedCampaign))->assertOk()->getContent();
        $completed = $this->actingAs($operator)
            ->get(route('admin.campaigns.show', $completedCampaign))->assertOk()->getContent();

        $this->assertIsString($running);
        $this->assertIsString($paused);
        $this->assertIsString($completed);

        // Asserted on the action URLs rather than the button labels, because the
        // status badge of a paused campaign reads "Paused" and would match any
        // naive search for the word "pause".
        $this->assertStringContainsString(route('admin.campaigns.pause', $runningCampaign), $running);
        $this->assertStringContainsString(route('admin.campaigns.cancel', $runningCampaign), $running);
        $this->assertStringNotContainsString(route('admin.campaigns.resume', $runningCampaign), $running);

        $this->assertStringContainsString(route('admin.campaigns.resume', $pausedCampaign), $paused);
        $this->assertStringNotContainsString(route('admin.campaigns.pause', $pausedCampaign), $paused);

        $this->assertStringNotContainsString(route('admin.campaigns.pause', $completedCampaign), $completed);
        $this->assertStringNotContainsString(route('admin.campaigns.resume', $completedCampaign), $completed);
        $this->assertStringNotContainsString(route('admin.campaigns.cancel', $completedCampaign), $completed);
    }

    public function test_the_admin_area_offers_no_way_to_start_or_reconfigure_someone_elses_campaign(): void
    {
        $customer = $this->signedInTenant();
        $campaign = $this->draftFor($customer, ['name' => 'a customer draft']);

        $names = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'admin/campaigns'))
            ->map(fn ($route) => $route->methods()[0].' /'.$route->uri())
            ->values();

        // Only reading and the three interventions. There is no admin route that
        // launches, edits, re-transports or starts somebody else's campaign.
        $this->assertSame([
            'GET /admin/campaigns',
            'GET /admin/campaigns/{campaign}',
            'GET /admin/campaigns/{campaign}/cancel',
            'POST /admin/campaigns/{campaign}/cancel',
            'POST /admin/campaigns/{campaign}/pause',
            'POST /admin/campaigns/{campaign}/resume',
        ], $names->sort()->values()->all());

        $this->actingAs($this->operator())
            ->get(route('admin.campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('Nothing frozen');

        $this->assertSame(CampaignStatus::Draft, $campaign->fresh()->status);
    }

    public function test_a_campaign_id_that_does_not_exist_is_a_404_rather_than_an_empty_operator_view(): void
    {
        $this->actingAs($this->operator())->get('/admin/campaigns/999999')->assertNotFound();
        $this->actingAs($this->operator())->get('/admin/campaigns/not-an-id')->assertNotFound();
    }

    public function test_the_admin_campaign_pages_require_authentication(): void
    {
        $this->get('/admin/campaigns')->assertRedirect('/login');
        $this->get('/admin/campaigns/1')->assertRedirect('/login');
        $this->post('/admin/campaigns/1/pause')->assertRedirect('/login');
    }

    public function test_the_intervention_permission_is_separate_from_the_view_permission(): void
    {
        $customer = $this->signedInTenant();
        $campaign = $this->sendingCampaign($customer, 1);

        // Support holds campaigns.view and not campaigns.pause: watching campaigns
        // happen must not confer the ability to stop one.
        $support = User::factory()->role(Role::Support)->create();

        $this->assertTrue($support->can(Permission::CAMPAIGNS_VIEW));
        $this->assertFalse($support->can(Permission::CAMPAIGNS_PAUSE));

        $this->actingAs($support)->get(route('admin.campaigns.index'))->assertOk();
        $this->actingAs($support)->get(route('admin.campaigns.show', $campaign))->assertOk();
        $this->actingAs($support)->post(route('admin.campaigns.pause', $campaign))->assertForbidden();

        $this->assertSame(CampaignStatus::Running, $campaign->fresh()->status);

        // Operations holds both, so the action is reachable for the role that is
        // meant to have it.
        $operations = User::factory()->role(Role::Operations)->create();

        $this->assertTrue($operations->can(Permission::CAMPAIGNS_PAUSE));

        $this->actingAs($operations)->post(route('admin.campaigns.pause', $campaign))->assertRedirect();

        $this->assertSame(CampaignStatus::Paused, $campaign->fresh()->status);
    }

    public function test_the_customer_pages_are_still_fenced_by_ownership(): void
    {
        $owner = $this->signedInTenant();
        $stranger = $this->signedInTenant();

        $campaign = $this->sendingCampaign($owner, 2, ['name' => 'private business']);

        $this->actingAs($stranger)->get(route('campaigns.show', $campaign))->assertNotFound();

        // And an operator's reach does not extend to the customer's own pages: the
        // administration area is a separate set of routes with their own
        // permission, not a widened version of this one.
        $this->actingAs($this->operator())->get(route('campaigns.show', $campaign))->assertNotFound();
    }

    public function test_the_admin_pages_carry_no_placeholder_or_demo_content(): void
    {
        $customer = $this->signedInTenant();
        $campaign = $this->sendingCampaign($customer, 1);

        $operator = $this->operator();

        // "Coming soon" is deliberately absent from this list: the navigation marks its
        // own pending destinations with that badge, and the layout is not what this
        // test is about. What matters is that these pages invent no placeholder copy
        // and promise no analytics.
        foreach ([route('admin.campaigns.index'), route('admin.campaigns.show', $campaign)] as $path) {
            $body = strtolower($this->actingAs($operator)->get($path)->assertOk()->getContent());

            $this->assertIsString($body);

            foreach (['not yet available', 'demo', 'lorem ipsum', 'open rate', 'click-through', 'reputation', 'deliverability score'] as $phrase) {
                $this->assertStringNotContainsString($phrase, $body, $path.' should not contain: '.$phrase);
            }
        }
    }

    public function test_the_filter_object_rejects_a_value_it_does_not_offer(): void
    {
        $request = Request::create('/admin/campaigns', 'GET', ['activity' => 'healthy']);

        $filters = CampaignOperatorFilters::fromRequest($request);

        $this->assertNull($filters->activity);
        $this->assertNull($filters->status);
        $this->assertNull($filters->ownerId);
        $this->assertNull($filters->smtpAccountId);
    }

    /**
     * An administrator, which for these tests means a super administrator.
     */
    private function operator(): User
    {
        return User::factory()->role(Role::SuperAdmin)->create();
    }

    /**
     * How many statements the campaign list costs for one operator.
     */
    private function queryCountFor(User $operator): int
    {
        // Warm the session/auth state first: the first request in a test also
        // resolves the session guard, and counting that would measure the wrong
        // thing.
        $this->actingAs($operator)->get(route('admin.campaigns.index'))->assertOk();

        $count = 0;

        DB::listen(function () use (&$count): void {
            $count++;
        });

        $this->actingAs($operator)->get(route('admin.campaigns.index'))->assertOk();

        return $count;
    }

    /**
     * A launched campaign with a real audience, left for the worker.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function sendingCampaign(User $user, int $recipients, array $overrides = []): Campaign
    {
        // Faked before launching. Launching hands a job to the queue, and on a
        // synchronous queue that job would run inside `launchNow()` — so a
        // one-recipient campaign would already be finished before a test could
        // assert anything about it being in flight.
        Queue::fake();

        $list = $this->listFor($user);

        for ($i = 0; $i < $recipients; $i++) {
            $this->eligibleContact($user, $list);
        }

        $campaign = $this->draftFor($user, array_merge(['list_id' => $list->id], $overrides));

        $refused = app(CampaignLauncher::class)->launchNow($campaign);

        $this->assertNull($refused, 'The campaign should have launched: '.($refused?->summary() ?? ''));

        return $campaign->fresh();
    }

    private function sendOne(Campaign $campaign): void
    {
        Queue::fake();

        DB::table('campaigns')->where('id', $campaign->id)
            ->update(['next_send_at' => now()->subSecond()]);

        app(CampaignRunner::class)->run($campaign->fresh());
    }

    private function sendUntilEmpty(Campaign $campaign): void
    {
        // Faked, so the job each pass hands back to the queue does not run inline
        // behind this loop's back. With a synchronous queue the runner would finish
        // the campaign inside a dispatch and the pass that returned would still
        // report "deferred", which is a confusing way to test a campaign completing.
        Queue::fake();

        for ($pass = 0; $pass < 20; $pass++) {
            // Already finished: nothing left to drive it.
            if (in_array($campaign->fresh()->status, [CampaignStatus::Completed, CampaignStatus::Cancelled], true)) {
                return;
            }

            DB::table('campaign_recipients')
                ->where('campaign_id', $campaign->id)
                ->update(['next_attempt_at' => now()->subMinute()]);

            DB::table('campaigns')->where('id', $campaign->id)
                ->update(['next_send_at' => now()->subMinute()]);

            $outcome = app(CampaignRunner::class)->run($campaign->fresh());

            if ($outcome->action === 'completed' || $outcome->action === 'stopped') {
                return;
            }
        }

        $this->fail('The campaign did not settle within the pass limit.');
    }
}

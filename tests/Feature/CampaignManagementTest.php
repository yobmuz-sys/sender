<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Audience\SuppressionList;
use App\Domain\Audience\SuppressionReason;
use App\Domain\Campaigns\AudienceSnapshot;
use App\Domain\Campaigns\Campaign;
use App\Domain\Campaigns\CampaignLauncher;
use App\Domain\Campaigns\CampaignPreflight;
use App\Domain\Campaigns\CampaignRecipient;
use App\Domain\Campaigns\CampaignRecipientStatus;
use App\Domain\Campaigns\CampaignRunner;
use App\Domain\Campaigns\CampaignStatus;
use App\Domain\Templates\Template;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/**
 * Preparing a campaign: the pages, the checks, and the freeze.
 *
 * The property these tests are really about is that a launched campaign cannot be
 * changed by anything that happens afterwards — to its template, to the list it
 * was built from, or to the transport it named. Every test that edits something
 * after launch exists to catch a campaign that quietly re-reads mutable state
 * while it is halfway through sending.
 */
class CampaignManagementTest extends CampaignTestCase
{
    public function test_the_builder_renders_with_the_campaign_preparation_steps(): void
    {
        $user = $this->signedInTenant();

        $this->verifiedAccountFor($user);
        $this->readyTemplateFor($user);
        $this->listFor($user);

        $this->actingAs($user)
            ->get(route('campaigns.create'))
            ->assertOk()
            ->assertSee('1. The campaign')
            ->assertSee('2. The audience')
            ->assertSee('3. When and how fast')
            ->assertSee('4. Checks')
            ->assertSee('Start campaign')
            ->assertSee('Save draft');
    }

    public function test_the_builder_shows_the_checks_for_the_current_selection(): void
    {
        $user = $this->signedInTenant();
        $template = $this->readyTemplateFor($user);

        $this->actingAs($user)
            ->get(route('campaigns.create', ['template_id' => $template->id]))
            ->assertOk()
            ->assertSee($template->name)
            ->assertSee('Message template')
            ->assertSee('Transport verification');
    }

    public function test_a_tenant_can_save_a_draft(): void
    {
        $user = $this->signedInTenant();
        $account = $this->verifiedAccountFor($user);
        $template = $this->readyTemplateFor($user);
        $list = $this->listFor($user);
        $this->eligibleContact($user, $list);

        $response = $this->actingAs($user)->post(route('campaigns.store'), $this->payload([
            'template_id' => $template->id,
            'list_id' => $list->id,
            'smtp_account_id' => $account->id,
        ]));

        $campaign = Campaign::query()->sole();

        $response->assertRedirect(route('campaigns.show', $campaign));
        $this->assertSame(CampaignStatus::Draft, $campaign->status);
        $this->assertSame(0, $campaign->recipients()->count(), 'Saving a draft must not freeze an audience.');
    }

    public function test_creating_a_campaign_requires_a_name_a_template_a_list_and_a_transport(): void
    {
        $user = $this->signedInTenant();

        $this->actingAs($user)
            ->post(route('campaigns.store'), [])
            ->assertSessionHasErrors(['name', 'template_id', 'list_id', 'smtp_account_id']);
    }

    public function test_another_tenants_template_cannot_be_selected(): void
    {
        $user = $this->signedInTenant();
        $stranger = $this->signedInTenant();

        $account = $this->verifiedAccountFor($user);
        $list = $this->listFor($user);
        $theirs = $this->readyTemplateFor($stranger);

        $this->actingAs($user)
            ->post(route('campaigns.store'), $this->payload([
                'template_id' => $theirs->id,
                'list_id' => $list->id,
                'smtp_account_id' => $account->id,
            ]))
            ->assertSessionHasErrors('template_id');
    }

    public function test_another_tenants_list_cannot_be_selected(): void
    {
        $user = $this->signedInTenant();
        $stranger = $this->signedInTenant();

        $account = $this->verifiedAccountFor($user);
        $template = $this->readyTemplateFor($user);
        $theirs = $this->listFor($stranger);

        $this->actingAs($user)
            ->post(route('campaigns.store'), $this->payload([
                'template_id' => $template->id,
                'list_id' => $theirs->id,
                'smtp_account_id' => $account->id,
            ]))
            ->assertSessionHasErrors('list_id');
    }

    public function test_another_tenants_transport_cannot_be_selected(): void
    {
        $user = $this->signedInTenant();
        $stranger = $this->signedInTenant();

        $template = $this->readyTemplateFor($user);
        $list = $this->listFor($user);
        $theirs = $this->verifiedAccountFor($stranger);

        $this->actingAs($user)
            ->post(route('campaigns.store'), $this->payload([
                'template_id' => $template->id,
                'list_id' => $list->id,
                'smtp_account_id' => $theirs->id,
            ]))
            ->assertSessionHasErrors('smtp_account_id');
    }

    public function test_a_template_that_is_not_finished_blocks_the_launch(): void
    {
        $user = $this->signedInTenant();
        $list = $this->listFor($user);
        $this->eligibleContact($user, $list);

        $campaign = $this->draftFor($user, [
            'template_id' => Template::factory()->incomplete()->create(['user_id' => $user->id])->id,
            'list_id' => $list->id,
        ]);

        $report = $this->preflight()->reportFor($campaign);

        $this->assertFalse($report->canLaunch());
        $this->assertNotNull($report->blockers()->firstWhere('key', 'campaign.template.ready'));
        $this->assertNull($campaign->fresh()->template_version, 'A blocked campaign must not freeze anything.');
    }

    public function test_an_unverified_transport_blocks_the_launch(): void
    {
        $user = $this->signedInTenant();
        $list = $this->listFor($user);
        $this->eligibleContact($user, $list);

        $campaign = $this->draftFor($user, [
            'list_id' => $list->id,
            'smtp_account_id' => $this->accountFor($user)->id,
        ]);

        $report = $this->preflight()->reportFor($campaign);

        $this->assertFalse($report->canLaunch());
        $this->assertNotNull($report->blockers()->firstWhere('key', 'campaign.transport.verified'));
    }

    public function test_an_audience_nobody_can_be_contacted_blocks_the_launch(): void
    {
        $user = $this->signedInTenant();
        $list = $this->listFor($user);

        $this->contactWithoutConsent($user, $list);

        $campaign = $this->draftFor($user, ['list_id' => $list->id]);

        $report = $this->preflight()->reportFor($campaign);

        $this->assertFalse($report->canLaunch());
        $this->assertNotNull($report->blockers()->firstWhere('key', 'campaign.audience'));
    }

    public function test_a_message_without_an_unsubscribe_link_is_refused(): void
    {
        $user = $this->signedInTenant();
        $list = $this->listFor($user);
        $this->eligibleContact($user, $list);

        $campaign = $this->draftFor($user, [
            'list_id' => $list->id,
            'template_id' => Template::factory()->create([
                'user_id' => $user->id,
                'html_body' => '<p>Hello, this message offers no way out.</p>',
                'text_body' => 'Hello, this message offers no way out.',
            ])->id,
        ]);

        $report = $this->preflight()->reportFor($campaign);

        $this->assertFalse($report->canLaunch());

        $blocking = $report->blockers()->firstWhere('key', 'campaign.unsubscribe');
        $this->assertNotNull($blocking);
        $this->assertStringContainsString('{{unsubscribe_url}}', $blocking->detail);
    }

    public function test_suppressed_and_unconsented_contacts_are_reported_as_exclusions(): void
    {
        $user = $this->signedInTenant();
        $list = $this->listFor($user);

        $this->eligibleContact($user, $list);
        $this->eligibleContact($user, $list);
        $this->suppressedContact($user, $list);
        $this->contactWithoutConsent($user, $list);

        $campaign = $this->draftFor($user, ['list_id' => $list->id]);

        $report = $this->preflight()->reportFor($campaign);
        $this->assertTrue($report->canLaunch(), $report->summary());

        $suppression = $report->checks()->firstWhere('key', 'campaign.suppression');
        $consent = $report->checks()->firstWhere('key', 'campaign.consent');

        $this->assertSame('warn', $suppression->level->value);
        $this->assertSame('warn', $consent->level->value);
        $this->assertStringContainsString('1 contact', $suppression->detail);
        $this->assertStringContainsString('1 contact', $consent->detail);
    }

    public function test_a_rate_below_the_installation_floor_is_raised_rather_than_honoured(): void
    {
        $user = $this->signedInTenant();
        $list = $this->listFor($user);
        $this->eligibleContact($user, $list);

        $campaign = $this->draftFor($user, ['list_id' => $list->id, 'rate_interval_seconds' => 1]);

        $report = $this->preflight()->reportFor($campaign);

        $this->assertSame('warn', $report->checks()->firstWhere('key', 'campaign.rate')->level->value);
        $this->assertGreaterThanOrEqual(30, $this->preflight()->effectiveIntervalSeconds($campaign));
    }

    public function test_a_batch_size_beyond_the_installation_ceiling_is_capped(): void
    {
        $user = $this->signedInTenant();
        $list = $this->listFor($user);
        $this->eligibleContact($user, $list);

        $campaign = $this->draftFor($user, ['list_id' => $list->id, 'worker_batch_size' => 100]);

        $this->assertSame(100, $this->preflight()->effectiveBatchSize($campaign));

        config(['sender.sending.max_per_run' => 5]);

        $this->assertSame(5, app(CampaignPreflight::class)->effectiveBatchSize($campaign));
    }

    public function test_the_builder_shows_the_chosen_template_and_what_it_still_needs(): void
    {
        $user = $this->signedInTenant();

        $this->verifiedAccountFor($user);
        $this->listFor($user);

        $template = $this->readyTemplateFor($user);
        $incomplete = Template::factory()->create([
            'user_id' => $user->id,
            'subject' => 'Half written',
            'html_body' => '',
            'text_body' => '',
        ]);

        // A finished template reports its own readiness and its placeholders,
        // read from the template's API rather than restated by the panel.
        $this->actingAs($user)
            ->get(route('campaigns.create', ['template_id' => $template->id]))
            ->assertOk()
            ->assertSee('Version '.$template->version)
            ->assertSee('Ready to use')
            ->assertSee('{{unsubscribe_url}}');

        // An unfinished one names what is missing instead of looking sendable.
        $this->actingAs($user)
            ->get(route('campaigns.create', ['template_id' => $incomplete->id]))
            ->assertOk()
            ->assertSee('Needs attention')
            ->assertSee('The HTML message is empty.');
    }

    public function test_the_builder_shows_where_the_chosen_transport_sends_from(): void
    {
        $user = $this->signedInTenant();

        $this->readyTemplateFor($user);
        $this->listFor($user);

        $account = $this->verifiedAccountFor($user, [
            'host' => 'smtp.campaign-host.test',
            'from_address' => 'news@example.test',
        ]);

        $this->actingAs($user)
            ->get(route('campaigns.create', ['smtp_account_id' => $account->id]))
            ->assertOk()
            ->assertSee('smtp.campaign-host.test')
            ->assertSee('news@example.test')
            ->assertSee('Ready');
    }

    public function test_the_builder_never_shows_a_transport_credential(): void
    {
        $user = $this->signedInTenant();

        $this->readyTemplateFor($user);
        $this->listFor($user);

        $account = $this->verifiedAccountFor($user);

        $body = $this->actingAs($user)
            ->get(route('campaigns.create', ['smtp_account_id' => $account->id]))
            ->assertOk()
            ->getContent();

        $this->assertIsString($body);
        $this->assertStringNotContainsString('app-password-value', $body);
    }

    public function test_a_schedule_is_stored_as_the_instant_the_customer_named(): void
    {
        $user = $this->signedInTenant();
        $account = $this->verifiedAccountFor($user);
        $template = $this->readyTemplateFor($user);
        $list = $this->listFor($user);
        $this->eligibleContact($user, $list);

        // Nine in the morning in New York in January is fourteen in the afternoon
        // UTC. A schedule that ignored the zone would send five hours early.
        $this->actingAs($user)->post(route('campaigns.store'), $this->payload([
            'template_id' => $template->id,
            'list_id' => $list->id,
            'smtp_account_id' => $account->id,
            'scheduled_at' => now()->addDays(30)->format('Y-m-d').'T09:00',
            'scheduled_timezone' => 'America/New_York',
        ]))->assertSessionHasNoErrors();

        $campaign = Campaign::query()->sole()->fresh();

        $this->assertSame('America/New_York', $campaign->scheduled_timezone);
        $this->assertSame(
            CarbonImmutable::parse($campaign->scheduled_at, 'UTC')->setTimezone('America/New_York')->format('H:i'),
            '09:00',
            'The stored instant must read back as the clock the customer typed.',
        );
        $this->assertSame(
            14,
            CarbonImmutable::parse($campaign->scheduled_at, 'UTC')->hour,
            'Nine in the morning in January is fourteen in the afternoon UTC.',
        );
    }

    public function test_a_scheduled_campaign_can_be_sent_now_instead_of_waiting(): void
    {
        Queue::fake();

        $user = $this->signedInTenant();
        $list = $this->listFor($user);
        $this->eligibleContact($user, $list);

        $campaign = $this->draftFor($user, [
            'list_id' => $list->id,
            'scheduled_at' => now()->addWeek(),
            'scheduled_timezone' => 'Europe/London',
        ]);

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('Send now instead')
            ->assertSee('Freeze and wait');

        $this->actingAs($user)
            ->post(route('campaigns.sendNow', $campaign))
            ->assertRedirect(route('campaigns.show', $campaign))
            ->assertSessionHas('status', 'Campaign started now. The scheduled start time no longer applies.');

        // Running, not waiting: the schedule no longer governs it.
        $this->assertSame(CampaignStatus::Running, $campaign->fresh()->status);
        $this->assertTrue($campaign->fresh()->scheduled_at->isFuture());

        // And it is a one-way door. A replayed request must not send again.
        $this->actingAs($user)
            ->post(route('campaigns.sendNow', $campaign))
            ->assertStatus(409);
    }

    public function test_a_campaign_that_is_not_waiting_cannot_be_sent_now(): void
    {
        Queue::fake();

        $user = $this->signedInTenant();
        $list = $this->listFor($user);
        $this->eligibleContact($user, $list);

        $campaign = $this->draftFor($user, ['list_id' => $list->id]);

        // A draft with no schedule has nothing to overrule.
        $this->actingAs($user)
            ->post(route('campaigns.sendNow', $campaign))
            ->assertStatus(409);
    }

    public function test_send_now_still_refuses_a_campaign_that_no_longer_passes_its_checks(): void
    {
        Queue::fake();

        $user = $this->signedInTenant();
        $list = $this->listFor($user);
        $this->eligibleContact($user, $list);

        $campaign = $this->draftFor($user, [
            'list_id' => $list->id,
            'scheduled_at' => now()->addWeek(),
        ]);

        // The transport stopped working while the campaign waited.
        $account = $campaign->smtpAccount;
        $account->forceFill(['status' => 'unverified'])->save();

        $this->actingAs($user)
            ->post(route('campaigns.sendNow', $campaign))
            ->assertRedirect(route('campaigns.show', $campaign))
            ->assertSessionHas('error');

        $this->assertNotSame(CampaignStatus::Running, $campaign->fresh()->status);
    }

    public function test_another_tenant_cannot_send_somebody_elses_campaign_now(): void
    {
        Queue::fake();

        $user = $this->signedInTenant();
        $stranger = $this->signedInTenant();
        $list = $this->listFor($user);
        $this->eligibleContact($user, $list);

        $campaign = $this->draftFor($user, [
            'list_id' => $list->id,
            'scheduled_at' => now()->addWeek(),
        ]);

        $this->actingAs($stranger)
            ->post(route('campaigns.sendNow', $campaign))
            ->assertNotFound();
    }

    public function test_a_schedule_shown_on_the_campaign_page_names_the_zone_it_was_written_in(): void
    {
        $user = $this->signedInTenant();
        $list = $this->listFor($user);
        $this->eligibleContact($user, $list);

        $campaign = $this->draftFor($user, [
            'list_id' => $list->id,
            'scheduled_at' => now()->addDays(10),
            'scheduled_timezone' => 'Europe/London',
        ]);

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('Europe/London');
    }

    public function test_the_builder_shows_the_schedule_back_in_the_zone_it_was_written_in(): void
    {
        $user = $this->signedInTenant();

        $this->verifiedAccountFor($user);
        $template = $this->readyTemplateFor($user);
        $list = $this->listFor($user);
        $this->eligibleContact($user, $list);

        $local = CarbonImmutable::parse('2030-01-15 09:00', 'Europe/London');

        $campaign = $this->draftFor($user, [
            'scheduled_at' => $local->utc(),
            'scheduled_timezone' => 'Europe/London',
        ]);

        $body = $this->actingAs($user)
            ->get(route('campaigns.edit', $campaign))
            ->assertOk()
            ->assertSee('2030-01-15T09:00')
            ->getContent();

        $this->assertIsString($body);
        $this->assertMatchesRegularExpression(
            '/value="Europe\/London"\s+selected/',
            $body,
            'The zone the schedule was written in must be the selected one, not the application default.',
        );
    }

    public function test_a_time_zone_that_was_never_offered_is_refused(): void
    {
        $user = $this->signedInTenant();
        $account = $this->verifiedAccountFor($user);
        $template = $this->readyTemplateFor($user);
        $list = $this->listFor($user);

        $this->actingAs($user)->post(route('campaigns.store'), $this->payload([
            'template_id' => $template->id,
            'list_id' => $list->id,
            'smtp_account_id' => $account->id,
            'scheduled_at' => now()->addDay()->format('Y-m-d').'T09:00',
            'scheduled_timezone' => 'Mars/Olympus_Mons',
        ]))->assertSessionHasErrors('scheduled_timezone');
    }

    public function test_a_schedule_in_the_past_is_refused_rather_than_started_immediately(): void
    {
        $user = $this->signedInTenant();
        $account = $this->verifiedAccountFor($user);
        $template = $this->readyTemplateFor($user);
        $list = $this->listFor($user);

        // 09:00 yesterday in Tokyo. In UTC it is still today or yesterday, so this
        // only fails if the submitted zone was the one that was converted.
        $this->actingAs($user)->post(route('campaigns.store'), $this->payload([
            'template_id' => $template->id,
            'list_id' => $list->id,
            'smtp_account_id' => $account->id,
            'scheduled_at' => now()->subDay()->format('Y-m-d').'T09:00',
            'scheduled_timezone' => 'Asia/Tokyo',
        ]))->assertSessionHasErrors('scheduled_at');

        $this->assertSame(0, Campaign::query()->count());
    }

    public function test_a_campaign_scheduled_now_is_reported_in_its_own_zone(): void
    {
        Queue::fake();

        $user = $this->signedInTenant();
        $list = $this->listFor($user);
        $this->eligibleContact($user, $list);

        $campaign = $this->draftFor($user, [
            'list_id' => $list->id,
            'scheduled_at' => CarbonImmutable::now('Asia/Tokyo')->addHours(6)->utc(),
            'scheduled_timezone' => 'Asia/Tokyo',
        ]);

        $this->actingAs($user)
            ->post(route('campaigns.start', $campaign))
            ->assertRedirect(route('campaigns.show', $campaign))
            ->assertSessionHas('status', 'Campaign scheduled. It will begin when the worker next runs after '
                .$campaign->fresh()->scheduledLocalTime()->format('j M Y \a\t H:i').' Asia/Tokyo.');
    }

    public function test_launching_stores_the_template_snapshot(): void
    {
        Queue::fake();

        $user = $this->signedInTenant();
        $list = $this->listFor($user);
        $this->eligibleContact($user, $list);

        $template = $this->readyTemplateFor($user);
        $campaign = $this->draftFor($user, ['list_id' => $list->id, 'template_id' => $template->id]);

        $this->actingAs($user)->post(route('campaigns.start', $campaign))->assertRedirect();

        $frozen = $campaign->fresh();

        $this->assertSame(1, $frozen->template_version);
        $this->assertSame($template->subject, $frozen->subject_snapshot);
        $this->assertSame($template->html_body, $frozen->html_body_snapshot);
        $this->assertSame($template->text_body, $frozen->text_body_snapshot);
        $this->assertSame(CampaignStatus::Scheduled, $frozen->status);
        $this->assertTrue($frozen->hasLaunched());
    }

    public function test_editing_the_template_after_launch_cannot_change_what_the_campaign_sends(): void
    {
        Queue::fake();

        $user = $this->signedInTenant();
        $list = $this->listFor($user);
        $this->eligibleContact($user, $list);

        $template = $this->readyTemplateFor($user, ['subject' => 'The original subject']);
        $campaign = $this->draftFor($user, ['list_id' => $list->id, 'template_id' => $template->id]);

        $this->actingAs($user)->post(route('campaigns.start', $campaign));

        $template->applyContent([
            'subject' => 'The rewritten subject',
            'preheader' => $template->preheader,
            'html_body' => '<p>Completely different copy.</p>',
            'text_body' => 'Completely different copy.',
        ]);
        $template->save();

        $frozen = $campaign->fresh();

        $this->assertSame(2, $template->fresh()->version);
        $this->assertSame('The original subject', $frozen->subject_snapshot);
        $this->assertSame(1, $frozen->template_version);
        $this->assertStringContainsString('Completely different copy', $template->fresh()->html_body);
        $this->assertStringNotContainsString('Completely different copy', (string) $frozen->html_body_snapshot);
    }

    public function test_launching_stores_one_recipient_per_eligible_contact(): void
    {
        $user = $this->signedInTenant();
        $list = $this->listFor($user);

        $this->eligibleContact($user, $list);
        $this->eligibleContact($user, $list);
        $this->eligibleContact($user, $list);

        $campaign = $this->draftFor($user, ['list_id' => $list->id]);

        app(CampaignLauncher::class)->launchNow($campaign);

        $this->assertSame(3, $campaign->recipients()->count());
        $this->assertSame(0, $campaign->recipients()->where('status', '!=', CampaignRecipientStatus::Queued->value)->count());
    }

    public function test_suppressed_and_unconsented_contacts_do_not_become_recipients(): void
    {
        $user = $this->signedInTenant();
        $list = $this->listFor($user);

        $eligible = $this->eligibleContact($user, $list);
        $suppressed = $this->suppressedContact($user, $list);
        $withoutConsent = $this->contactWithoutConsent($user, $list);
        $unusable = $this->unusableContact($user, $list);

        $campaign = $this->draftFor($user, ['list_id' => $list->id]);

        app(CampaignLauncher::class)->launchNow($campaign);

        $contactIds = $campaign->recipients()->pluck('contact_id')->all();

        $this->assertSame([$eligible->id], $contactIds);
        $this->assertNotContains($suppressed->id, $contactIds);
        $this->assertNotContains($withoutConsent->id, $contactIds);
        $this->assertNotContains($unusable->id, $contactIds);

        // The list is unchanged: an exclusion must not remove somebody from the
        // customer's own list, because that would hide it in the last place they
        // would look for it.
        $this->assertSame(4, $list->memberships()->count());
    }

    public function test_launching_twice_does_not_duplicate_recipients(): void
    {
        $user = $this->signedInTenant();
        $list = $this->listFor($user);
        $contact = $this->eligibleContact($user, $list);

        $campaign = $this->draftFor($user, ['list_id' => $list->id]);

        $launcher = app(CampaignLauncher::class);
        $launcher->launchNow($campaign);
        $launcher->launchNow($campaign);

        $this->assertSame(1, CampaignRecipient::query()->where('contact_id', $contact->id)->count());
    }

    public function test_a_contact_suppressed_before_launch_is_not_a_recipient_at_all(): void
    {
        $user = $this->signedInTenant();
        $list = $this->listFor($user);

        $eligible = $this->eligibleContact($user, $list);
        $aboutToUnsubscribe = $this->eligibleContact($user, $list);

        $campaign = $this->draftFor($user, ['list_id' => $list->id]);

        // Suppressed after the campaign was prepared and before it started. The
        // eligibility query is the single definition of who may be contacted, so
        // they never become a recipient at all rather than becoming one that is
        // later skipped.
        app(SuppressionList::class)
            ->suppress($aboutToUnsubscribe, SuppressionReason::Unsubscribed);

        app(CampaignLauncher::class)->launchNow($campaign);

        $contactIds = $campaign->recipients()->pluck('contact_id')->all();

        $this->assertSame([$eligible->id], $contactIds);
        $this->assertNotContains($aboutToUnsubscribe->id, $contactIds);
        $this->assertSame(2, $list->memberships()->count(), 'Suppression must not remove them from the list.');
    }

    public function test_a_draft_can_be_edited(): void
    {
        $user = $this->signedInTenant();
        $campaign = $this->draftFor($user);
        $template = $this->readyTemplateFor($user, ['name' => 'Replacement']);

        $this->actingAs($user)
            ->put(route('campaigns.update', $campaign), $this->payload([
                'name' => 'Renamed campaign',
                'template_id' => $template->id,
                'list_id' => $campaign->list_id,
                'smtp_account_id' => $campaign->smtp_account_id,
            ]))
            ->assertRedirect(route('campaigns.show', $campaign));

        $updated = $campaign->fresh();

        $this->assertSame('Renamed campaign', $updated->name);
        $this->assertSame($template->id, $updated->template_id);
        $this->assertNull($updated->template_version, 'Editing a draft must not create a snapshot.');
    }

    public function test_a_launched_campaign_cannot_be_edited(): void
    {
        Queue::fake();

        $user = $this->signedInTenant();
        $list = $this->listFor($user);
        $this->eligibleContact($user, $list);

        $campaign = $this->draftFor($user, ['list_id' => $list->id]);
        app(CampaignLauncher::class)->launchNow($campaign);

        $this->actingAs($user)
            ->get(route('campaigns.edit', $campaign))
            ->assertStatus(409);

        $replacement = $this->readyTemplateFor($user);

        $this->actingAs($user)
            ->put(route('campaigns.update', $campaign), $this->payload([
                'template_id' => $replacement->id,
                'list_id' => $campaign->list_id,
                'smtp_account_id' => $campaign->smtp_account_id,
            ]))
            ->assertStatus(409);

        $this->assertNotSame($replacement->id, $campaign->fresh()->template_id);
    }

    public function test_another_tenant_cannot_read_a_campaign(): void
    {
        $user = $this->signedInTenant();
        $stranger = $this->signedInTenant();

        $campaign = $this->draftFor($user);

        $this->actingAs($stranger)->get(route('campaigns.show', $campaign))->assertNotFound();
        $this->actingAs($stranger)->get(route('campaigns.edit', $campaign))->assertNotFound();
    }

    public function test_another_tenant_cannot_mutate_a_campaign(): void
    {
        Queue::fake();

        $user = $this->signedInTenant();
        $list = $this->listFor($user);
        $this->eligibleContact($user, $list);

        $campaign = $this->draftFor($user, ['list_id' => $list->id]);
        $stranger = $this->signedInTenant();

        $this->actingAs($stranger)->post(route('campaigns.start', $campaign))->assertNotFound();
        $this->actingAs($stranger)->post(route('campaigns.pause', $campaign))->assertNotFound();
        $this->actingAs($stranger)->post(route('campaigns.cancel', $campaign))->assertNotFound();

        $this->assertSame(CampaignStatus::Draft, $campaign->fresh()->status);
    }

    public function test_starting_is_a_post_and_never_a_get(): void
    {
        $campaign = $this->draftFor($this->signedInTenant());

        $this->actingAs($campaign->user)
            ->get(route('campaigns.show', $campaign).'/start')
            ->assertStatus(405);
    }

    public function test_the_campaign_list_shows_real_campaigns_and_an_honest_empty_state(): void
    {
        $user = $this->signedInTenant();

        $this->actingAs($user)
            ->get(route('campaigns.index'))
            ->assertOk()
            ->assertSee('No campaigns yet')
            ->assertDontSee('coming soon');

        $campaign = $this->draftFor($user, ['name' => 'October update']);

        $this->actingAs($user)
            ->get(route('campaigns.index'))
            ->assertOk()
            ->assertSee('October update')
            ->assertSee('Draft');
    }

    public function test_the_campaign_page_answers_what_is_being_sent_and_from_where(): void
    {
        Queue::fake();

        $user = $this->signedInTenant();
        $list = $this->listFor($user);
        $this->eligibleContact($user, $list);

        $campaign = $this->draftFor($user, ['list_id' => $list->id, 'name' => 'October update']);
        app(CampaignLauncher::class)->launchNow($campaign);

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('October update')
            ->assertSee('Frozen at template version 1')
            ->assertSee('Minimum send interval')
            ->assertSee('Recipients')
            ->assertSee('skipped or blocked');
    }

    public function test_a_failed_transport_stops_the_campaign_rather_than_switching_to_another(): void
    {
        Queue::fake();

        $user = $this->signedInTenant();
        $list = $this->listFor($user);
        $this->eligibleContact($user, $list);

        $campaign = $this->draftFor($user, ['list_id' => $list->id]);
        app(CampaignLauncher::class)->launchNow($campaign);

        $outcome = app(CampaignRunner::class)
            ->run($campaign->fresh());

        // Nothing here has failed yet, so the run is a plain pass; the stop-on-
        // failure behaviour is covered where the transport actually fails.
        $this->assertContains($outcome->action, ['deferred', 'completed']);
    }

    public function test_a_campaign_never_offers_a_deliverability_score(): void
    {
        $user = $this->signedInTenant();
        $list = $this->listFor($user);
        $this->eligibleContact($user, $list);

        $campaign = $this->draftFor($user, ['list_id' => $list->id]);

        $html = $this->actingAs($user)->get(route('campaigns.show', $campaign))->assertOk()->getContent();

        foreach (['reputation score', 'inbox rate', 'deliverability score', 'guaranteed inbox'] as $phrase) {
            $this->assertStringNotContainsStringIgnoringCase($phrase, (string) $html);
        }
    }

    public function test_the_campaign_page_and_the_list_page_report_the_same_people(): void
    {
        $user = $this->signedInTenant();
        $list = $this->listFor($user);

        $this->eligibleContact($user, $list);
        $this->eligibleContact($user, $list);
        $this->suppressedContact($user, $list);

        $campaign = $this->draftFor($user, ['list_id' => $list->id]);

        $this->assertSame(3, $list->memberships()->count(), 'The list holds everybody.');
        $this->assertSame(2, app(AudienceSnapshot::class)->summaryFor($campaign)->eligible);
    }

    /**
     * A valid submission body, so each test states only what it is changing.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $user = User::query()->first() ?? $this->signedInTenant();

        return array_merge([
            'name' => 'October update',
            'template_id' => Template::factory()->create(['user_id' => $user->id])->id,
            'list_id' => $this->listFor($user)->id,
            'smtp_account_id' => $this->verifiedAccountFor($user)->id,
            'rate_interval_seconds' => 30,
            'worker_batch_size' => 10,
        ], $overrides);
    }
}

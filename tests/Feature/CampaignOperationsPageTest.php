<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Audience\SuppressionList;
use App\Domain\Audience\SuppressionReason;
use App\Domain\Campaigns\Campaign;
use App\Domain\Campaigns\CampaignLauncher;
use App\Domain\Campaigns\CampaignOperations;
use App\Domain\Campaigns\CampaignRecipientLog;
use App\Domain\Campaigns\CampaignRecipientLogFilters;
use App\Domain\Campaigns\CampaignRecipientStatus;
use App\Domain\Campaigns\CampaignRunner;
use App\Domain\Campaigns\CampaignStatus;
use App\Domain\Campaigns\CampaignSummary;
use App\Domain\Campaigns\DeliveryAttempt;
use App\Domain\Mail\DeliveryOutcome;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * The campaign operations page, exercised against real transitions.
 *
 * Every test here gets its campaign into the state it is asserting about by
 * launching it and running the worker, rather than by writing a status column.
 * A page test that hand-builds its subject proves the page renders what it was
 * told; these prove it renders what the engine actually left behind — which is the
 * only version of the claim worth making about an operations screen.
 */
class CampaignOperationsPageTest extends CampaignTestCase
{
    public function test_a_draft_leads_with_its_state_and_admits_nothing_has_happened(): void
    {
        $user = $this->signedInTenant();
        $campaign = $this->draftFor($user, ['name' => 'October update']);

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('Not started. Nothing has been frozen or sent.')
            ->assertSee('Draft')
            ->assertSee('Nothing frozen yet')
            ->assertSee('No recipients yet')
            // A draft has no audience, so it has no percentage. "0%" would be a
            // claim about a denominator that does not exist.
            ->assertDontSee('0%');
    }

    public function test_a_running_campaign_reports_what_the_worker_has_actually_done(): void
    {
        $user = $this->signedInTenant();
        $campaign = $this->sendingCampaign($user, 3, ['name' => 'October update']);

        $this->sendOne($campaign);

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('Sending')
            ->assertSee('1 accepted by a server, 2 to go.')
            ->assertSee('33%')
            ->assertSee('Last accepted by a server')
            ->assertSee('Nothing is waiting on a timer.');
    }

    public function test_the_snapshot_is_the_campaigns_own_copy_not_the_live_template(): void
    {
        $user = $this->signedInTenant();

        $template = $this->readyTemplateFor($user, [
            'name' => 'October newsletter',
            'subject' => 'What changed in October',
            'preheader' => 'Three things, in four minutes.',
        ]);

        $list = $this->listFor($user);
        $this->eligibleContact($user, $list);

        $campaign = $this->draftFor($user, ['list_id' => $list->id, 'template_id' => $template->id]);
        app(CampaignLauncher::class)->launchNow($campaign);

        // The template is renamed and rewritten after the campaign froze it, and
        // the list gains somebody. Neither may appear on this page.
        $template->applyContent([
            'subject' => 'A subject the campaign never sent',
            'preheader' => null,
            'html_body' => '<p>Rewritten after launch.</p>',
            'text_body' => 'Rewritten after launch.',
        ]);

        $this->eligibleContact($user, $list);

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('October newsletter')
            ->assertSee('at version 1')
            ->assertSee('What changed in October')
            ->assertSee('Three things, in four minutes.')
            ->assertDontSee('A subject the campaign never sent')
            ->assertDontSee('Rewritten after launch');
    }

    public function test_a_deleted_template_leaves_the_campaign_still_able_to_name_itself(): void
    {
        $user = $this->signedInTenant();

        $template = $this->readyTemplateFor($user, [
            'name' => 'October newsletter',
            'subject' => 'What changed in October',
        ]);

        $list = $this->listFor($user);
        $this->eligibleContact($user, $list);

        $campaign = $this->draftFor($user, ['list_id' => $list->id, 'template_id' => $template->id]);
        app(CampaignLauncher::class)->launchNow($campaign);

        $template->delete();

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('October newsletter')
            ->assertSee('What changed in October');
    }

    public function test_the_page_explains_a_manual_pause_and_offers_the_safe_next_step(): void
    {
        $user = $this->signedInTenant();
        $campaign = $this->sendingCampaign($user, 4);

        $campaign->pause();

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('Sending paused')
            ->assertSee('4 recipients are waiting, unsent.')
            ->assertSee('What to do:')
            ->assertSee('Resume');
    }

    public function test_a_stopped_transport_is_explained_without_being_worked_around(): void
    {
        $user = $this->signedInTenant();
        $campaign = $this->sendingCampaign($user, 3);

        $this->transport->answering(DeliveryOutcome::AuthenticationRejected, '535', 'Authentication credentials invalid');
        $this->sendOne($campaign);

        $this->assertSame(CampaignStatus::Failed, $campaign->fresh()->status);

        $body = $this->actingAs($user)->get(route('campaigns.show', $campaign))->assertOk()->getContent();

        $this->assertIsString($body);
        $this->assertStringContainsString('Sending stopped by a problem', $body);
        $this->assertStringContainsString('stopped rather than switched to another transport', $body);
        $this->assertStringContainsString('535', $body);

        // The page must refuse the evasion the failure state exists to prevent,
        // and say so in the platform's own words rather than by omission.
        $this->assertStringContainsString('will not move it to another account for you', $body);
    }

    public function test_a_failed_campaign_cannot_be_resumed_but_a_paused_one_can(): void
    {
        $user = $this->signedInTenant();

        $failed = $this->sendingCampaign($user, 2);
        $this->transport->answering(DeliveryOutcome::AuthenticationRejected, '535', 'Authentication credentials invalid');
        $this->sendOne($failed);

        $paused = $this->sendingCampaign($user, 2);
        $paused->pause();

        $this->actingAs($user)->post(route('campaigns.resume', $failed->fresh()))->assertStatus(409);

        $this->actingAs($user)->post(route('campaigns.resume', $paused->fresh()))->assertRedirect();
        $this->assertSame(CampaignStatus::Running, $paused->fresh()->status);
    }

    public function test_a_temporary_failure_is_shown_as_a_queued_retry_with_a_time_not_a_failure(): void
    {
        $user = $this->signedInTenant();
        $campaign = $this->sendingCampaign($user, 2);

        $this->transport->answerNext(DeliveryOutcome::TemporaryFailure, '451', 'Try again later');
        $this->sendOne($campaign);

        $recipient = $campaign->recipients()->orderBy('id')->firstOrFail();

        $this->assertSame(CampaignRecipientStatus::Queued, $recipient->status);
        $this->assertNotNull($recipient->next_attempt_at);

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign))
            ->assertOk()
            // A throttled server is not a dead address, and the page must not read
            // as though it were.
            ->assertSee('Waiting')
            ->assertSee('After a temporary failure, not before.')
            ->assertSee('Next retry due');
    }

    public function test_the_recipient_log_pages_and_does_not_load_the_whole_audience(): void
    {
        $user = $this->signedInTenant();
        $campaign = $this->sendingCampaign($user, 3);

        $response = $this->actingAs($user)->get(route('campaigns.show', $campaign))->assertOk();
        $recipients = $response->viewData('recipients');

        $this->assertSame(3, $recipients->total());
        $this->assertSame(3, $recipients->count());

        // The paginator is server-side: fifty a page, not "however many".
        $this->assertSame(50, $recipients->perPage());
    }

    public function test_the_recipient_log_can_be_searched_and_filtered(): void
    {
        $user = $this->signedInTenant();
        $campaign = $this->sendingCampaign($user, 3);

        $this->sendOne($campaign);
        $this->transport->answerNext(DeliveryOutcome::PermanentFailure, '550', 'No such mailbox');
        $this->sendOne($campaign);

        $sent = $campaign->recipients()->where('status', CampaignRecipientStatus::Sent->value)->firstOrFail();

        $this->actingAs($user)
            ->get(route('campaigns.show', ['campaign' => $campaign, 'recipient_status' => 'sent']))
            ->assertOk()
            ->assertSee($sent->email)
            ->assertDontSee('There was nobody to send to');

        $this->actingAs($user)
            ->get(route('campaigns.show', ['campaign' => $campaign, 'recipient' => 'nobody@']))
            ->assertOk()
            ->assertSee('No recipients match');

        $this->actingAs($user)
            ->get(route('campaigns.show', ['campaign' => $campaign, 'recipient' => $sent->email]))
            ->assertOk()
            ->assertSee($sent->email);
    }

    public function test_a_search_term_with_a_wildcard_is_text_rather_than_everyone(): void
    {
        $user = $this->signedInTenant();
        $campaign = $this->sendingCampaign($user, 2);

        $this->actingAs($user)
            ->get(route('campaigns.show', ['campaign' => $campaign, 'recipient' => '%']))
            ->assertOk()
            ->assertSee('No recipients match');
    }

    public function test_a_recipient_status_that_does_not_exist_is_ignored(): void
    {
        $user = $this->signedInTenant();
        $campaign = $this->sendingCampaign($user, 2);

        $this->actingAs($user)
            ->get(route('campaigns.show', ['campaign' => $campaign, 'recipient_status' => 'exploded']))
            ->assertOk()
            ->assertDontSee('No recipients match');
    }

    public function test_a_recipient_filter_with_nothing_in_it_is_offered_as_disabled(): void
    {
        $user = $this->signedInTenant();
        $campaign = $this->sendingCampaign($user, 2);

        $counts = $this->actingAs($user)
            ->get(route('campaigns.show', $campaign))
            ->assertOk()
            ->viewData('recipientStatusCounts');

        $this->assertSame(2, $counts[CampaignRecipientStatus::Queued->value]);
        $this->assertSame(0, $counts[CampaignRecipientStatus::Failed->value]);
    }

    public function test_attempt_history_is_rendered_for_a_recipient_that_was_retried(): void
    {
        $user = $this->signedInTenant();
        $campaign = $this->sendingCampaign($user, 1);

        $this->transport->answerNext(DeliveryOutcome::TemporaryFailure, '451', 'Come back later');
        $this->sendOne($campaign);
        $this->makeRetriesDue($campaign);
        $this->sendOne($campaign);

        $recipient = $campaign->recipients()->firstOrFail();

        $this->assertSame(2, $recipient->attempts);
        $this->assertCount(2, $recipient->deliveryAttempts);

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('2 attempt(s) recorded')
            ->assertSee('Temporary failure')
            ->assertSee('451')
            ->assertSee('Come back later');
    }

    public function test_stored_server_text_is_scrubbed_before_it_is_displayed(): void
    {
        $user = $this->signedInTenant();
        $campaign = $this->sendingCampaign($user, 1);

        // A connection error routinely quotes the configuration that caused it.
        // The row keeps it verbatim as evidence; the page must not print it.
        $this->transport->answerNext(
            DeliveryOutcome::TemporaryFailure,
            '421',
            'failed to connect to smtp://alice:app-password-value@mail.example.test:587',
        );

        $this->sendOne($campaign);

        $stored = $campaign->recipients()->firstOrFail()->last_error_message;

        $this->assertStringContainsString('app-password-value', $stored, 'The row should keep the raw text.');

        $body = $this->actingAs($user)->get(route('campaigns.show', $campaign))->assertOk()->getContent();

        $this->assertIsString($body);
        $this->assertStringNotContainsString('app-password-value', $body);
        $this->assertStringContainsString('[redacted]', $body);
    }

    public function test_no_smtp_credential_appears_anywhere_on_the_page(): void
    {
        $user = $this->signedInTenant();
        $campaign = $this->sendingCampaign($user, 3);

        $this->sendOne($campaign);

        $body = $this->actingAs($user)->get(route('campaigns.show', $campaign))->assertOk()->getContent();

        $this->assertIsString($body);

        // The From identity is shown, because recipients see it. The secret never
        // is, and the username is not either — a campaign page gets left open on a
        // shared screen.
        $this->assertStringContainsString('Alice', $body);
        $this->assertStringNotContainsString('app-password-value', $body);
    }

    public function test_the_timeline_reports_only_what_the_campaign_recorded(): void
    {
        $user = $this->signedInTenant();
        $campaign = $this->sendingCampaign($user, 2, ['name' => 'October update']);

        $this->sendOne($campaign);

        $operations = $this->operationsFor($campaign);

        $labels = array_map(fn ($entry) => $entry->label, $operations->timeline);

        $this->assertContains('Created', $labels);
        $this->assertContains('Started sending', $labels);
        $this->assertContains('First submission attempted', $labels);
        $this->assertContains('Last accepted by a server', $labels);
        $this->assertContains('Last activity', $labels);

        // No event log exists for these, so they must not be invented.
        $this->assertNotContains('Preflight passed', $labels);
        $this->assertNotContains('Paused automatically', $labels);

        foreach ($operations->timeline as $entry) {
            $this->assertNotNull($entry->at, 'Every timeline entry has to carry a real timestamp.');
        }
    }

    public function test_a_completed_campaign_says_every_recipient_was_dealt_with(): void
    {
        $user = $this->signedInTenant();
        $campaign = $this->sendingCampaign($user, 3);

        $this->sendUntilEmpty($campaign);

        $this->assertSame(CampaignStatus::Completed, $campaign->fresh()->status);

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('Completed')
            ->assertSee('3 recipients dealt with. Nothing further will be sent.')
            ->assertSee('100%')
            // Terminal work has nothing left to act on.
            ->assertDontSee('Pause')
            ->assertDontSee('Resume');
    }

    public function test_a_cancelled_campaign_records_its_waiting_recipients_as_skipped(): void
    {
        $user = $this->signedInTenant();
        $campaign = $this->sendingCampaign($user, 3);

        $this->sendOne($campaign);
        $campaign->cancel();

        $this->assertSame(2, $campaign->recipients()->where('status', CampaignRecipientStatus::Skipped->value)->count());

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('Cancelled. Nothing further will be sent.')
            ->assertSee('Campaign cancelled')
            ->assertSee('The campaign was cancelled before this message was sent.');
    }

    public function test_a_blocked_recipient_is_explained_as_a_decision_not_an_error(): void
    {
        $user = $this->signedInTenant();
        $campaign = $this->sendingCampaign($user, 2);

        // Someone unsubscribes after the snapshot but before their turn comes up,
        // which is the only way a recipient reaches `blocked`.
        $contact = $campaign->recipients()->firstOrFail()->contact;

        app(SuppressionList::class)->suppress($contact, SuppressionReason::Unsubscribed, source: 'test');

        $this->sendOne($campaign);

        $blocked = $campaign->recipients()->where('status', CampaignRecipientStatus::Blocked->value)->first();

        $this->assertNotNull($blocked, 'The suppressed recipient should have been blocked, not sent.');
        $this->assertSame(0, $blocked->attempts, 'Blocked before submission, so no attempt was made.');

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('Blocked')
            ->assertSee('asked not to be contacted before their message was due');
    }

    public function test_a_scheduled_campaign_reports_its_start_time_and_its_own_actions(): void
    {
        $user = $this->signedInTenant();

        $list = $this->listFor($user);
        $this->eligibleContact($user, $list);

        $campaign = $this->draftFor($user, [
            'list_id' => $list->id,
            'name' => 'later today',
            'scheduled_at' => now()->addDays(2),
            'scheduled_timezone' => 'Europe/London',
        ]);

        $this->assertNull(app(CampaignLauncher::class)->launch($campaign));

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign->fresh()))
            ->assertOk()
            ->assertSee('Scheduled')
            ->assertSee('Europe/London')
            ->assertSee('Send now instead')
            ->assertSee('0%')
            ->assertDontSee('Pause');
    }

    public function test_a_stalled_campaign_says_when_it_was_last_active_rather_than_looking_idle(): void
    {
        $user = $this->signedInTenant();
        $campaign = $this->sendingCampaign($user, 2);

        $this->sendOne($campaign);

        $campaign->forceFill(['last_activity_at' => now()->subHours(3)])->save();

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('Last activity')
            ->assertSee('3 hours ago');

        // No live worker claim, so the page must not claim one is working.
        $this->assertFalse($this->operationsFor($campaign)->workerIsActive());
    }

    public function test_the_page_reports_a_worker_claim_without_polling_for_one(): void
    {
        $user = $this->signedInTenant();
        $campaign = $this->sendingCampaign($user, 2);

        DB::table('campaigns')->where('id', $campaign->id)
            ->update(['worker_claimed_at' => now()]);

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('A worker is processing this campaign right now');
    }

    public function test_another_tenant_cannot_read_a_campaign_operations_page(): void
    {
        $user = $this->signedInTenant();
        $stranger = $this->signedInTenant();

        $campaign = $this->sendingCampaign($user, 2, ['name' => 'private business']);

        $this->actingAs($stranger)
            ->get(route('campaigns.show', $campaign))
            ->assertNotFound();
    }

    public function test_a_recipient_of_one_campaign_cannot_be_reached_through_another(): void
    {
        $user = $this->signedInTenant();

        $mine = $this->sendingCampaign($user, 2, ['name' => 'mine']);
        $theirs = $this->sendingCampaign($user, 2, ['name' => 'theirs']);

        $recipient = $mine->recipients()->firstOrFail();
        $this->transport->answerNext(DeliveryOutcome::PermanentFailure, '550', 'No such mailbox');
        $this->sendOne($mine);

        // The log is scoped by campaign, so filtering one campaign cannot surface
        // another's recipients.
        $filters = CampaignRecipientLogFilters::fromRequest(
            Request::create('/campaigns/x', 'GET', ['recipient' => $recipient->email])
        );

        $page = app(CampaignRecipientLog::class)->paginateFor($theirs, $filters);

        $this->assertSame(0, $page->total());
    }

    public function test_actions_on_the_page_match_the_domain_for_every_state(): void
    {
        $user = $this->signedInTenant();

        $draft = $this->draftFor($user);

        $running = $this->sendingCampaign($user, 2);
        $paused = $this->sendingCampaign($user, 2);
        $paused->pause();

        $completed = $this->sendingCampaign($user, 2);
        $this->sendUntilEmpty($completed);

        $failed = $this->sendingCampaign($user, 2);
        $this->transport->answering(DeliveryOutcome::AuthenticationRejected, '535', 'Authentication credentials invalid');
        $this->sendOne($failed);

        $expectations = [
            'draft' => ['Start campaign', 'Edit'],
            'running' => ['Pause', 'Cancel campaign'],
            'paused' => ['Resume', 'Cancel campaign'],
            'completed' => ['Open'],
            'failed' => ['Open'],
        ];

        $campaigns = [
            'draft' => $draft,
            'running' => $running->fresh(),
            'paused' => $paused->fresh(),
            'completed' => $completed->fresh(),
            'failed' => $failed->fresh(),
        ];

        foreach ($expectations as $key => $expected) {
            $body = $this->actingAs($user)->get(route('campaigns.show', $campaigns[$key]))->getContent();

            $this->assertIsString($body);

            foreach ($expected as $label) {
                $this->assertStringContainsString($label, $body, 'A '.$key.' campaign should offer '.$label.'.');
            }
        }

        // The actions offered are exactly the ones the state model permits, which
        // is the property that stops a page showing a button the POST would refuse.
        $this->assertSame(
            ['open', 'edit'],
            $this->actionsFor($campaigns['draft']),
        );
        $this->assertSame(
            ['open', 'pause', 'cancel'],
            $this->actionsFor($campaigns['running']),
        );
        $this->assertSame(
            ['open', 'resume', 'cancel'],
            $this->actionsFor($campaigns['paused']),
        );
        $this->assertSame(['open'], $this->actionsFor($campaigns['completed']));
        $this->assertSame(['open'], $this->actionsFor($campaigns['failed']));
    }

    public function test_a_forbidden_action_is_refused_by_the_endpoint_and_not_only_hidden(): void
    {
        $user = $this->signedInTenant();

        $draft = $this->draftFor($user);
        $paused = $this->sendingCampaign($user, 2);
        $paused->pause();

        // Pause a draft, resume a draft, resume a paused-but-completed campaign:
        // each is refused server-side regardless of what a browser rendered.
        $this->actingAs($user)->post(route('campaigns.pause', $draft))->assertStatus(409);
        $this->actingAs($user)->post(route('campaigns.resume', $draft))->assertStatus(409);
        $this->actingAs($user)->post(route('campaigns.start', $paused->fresh()))->assertStatus(409);

        $this->assertSame(CampaignStatus::Draft, $draft->fresh()->status);
        $this->assertSame(CampaignStatus::Paused, $paused->fresh()->status);
    }

    public function test_the_page_carries_no_demo_or_placeholder_content(): void
    {
        $user = $this->signedInTenant();

        // A draft rather than a launched campaign, because the banned phrases are
        // ordinary words and a generated contact address is free to contain them —
        // `lorem_ipsum@example.org` would otherwise fail a test about placeholder
        // copy.
        $campaign = $this->draftFor($user);

        $body = strtolower($this->actingAs($user)->get(route('campaigns.show', $campaign))->getContent());

        $this->assertIsString($body);

        // "Coming soon" is deliberately absent from this list: the navigation marks
        // its own pending destinations with it, and that badge is the layout's
        // business rather than this page's. What matters here is that the page
        // itself invents no placeholder copy and promises no analytics.
        foreach (['lorem ipsum', 'demo campaign', 'not yet available', 'open rate', 'click rate', 'bounce rate', 'reputation score', 'click-through'] as $phrase) {
            $this->assertStringNotContainsString($phrase, $body, 'The page should not contain: '.$phrase);
        }
    }

    public function test_the_page_is_reachable_and_is_not_a_placeholder_shell(): void
    {
        $user = $this->signedInTenant();
        $campaign = $this->sendingCampaign($user, 1);

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign))
            ->assertOk()
            ->assertDontSee('not yet available')
            ->assertSee('Campaign snapshot');
    }

    public function test_a_campaign_that_sends_completes_and_then_reports_no_pending_work(): void
    {
        $user = $this->signedInTenant();

        // The whole sequence, driven through the engine rather than asserted at:
        // launch, one accepted message, a throttled message retried, then the
        // campaign finishing when nothing is left to send.
        $campaign = $this->sendingCampaign($user, 2);

        $this->sendOne($campaign);
        $this->assertSame(1, $campaign->recipients()->where('status', CampaignRecipientStatus::Sent->value)->count());

        $this->transport->answerNext(DeliveryOutcome::TemporaryFailure, '451', 'Later');
        $this->sendOne($campaign);
        $this->assertSame(1, $campaign->recipients()->where('status', CampaignRecipientStatus::Queued->value)->count());

        $this->makeRetriesDue($campaign);
        $this->sendUntilEmpty($campaign);

        $fresh = $campaign->fresh();

        $this->assertSame(CampaignStatus::Completed, $fresh->status);
        $this->assertSame(2, $fresh->recipients()->where('status', CampaignRecipientStatus::Sent->value)->count());
        $this->assertFalse($this->operationsFor($fresh)->hasPendingRecipients());

        // Three attempts in total: two accepted, and the throttled one retried and
        // accepted. The history is what makes that sequence legible afterwards.
        $this->assertSame(3, DeliveryAttempt::query()->whereIn(
            'campaign_recipient_id',
            $fresh->recipients()->pluck('id'),
        )->count());

        $operations = $this->operationsFor($fresh);

        $this->assertNotNull($operations->lastSuccessAt);
        $this->assertNotNull($operations->lastFailure, 'The earlier throttle is still in the history.');
        $this->assertSame('451', $operations->lastFailureSummary()['code']);
    }

    /**
     * A launched campaign with a real audience, left for the worker to send.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function sendingCampaign(User $user, int $recipients, array $overrides = []): Campaign
    {
        $list = $this->listFor($user);

        for ($i = 0; $i < $recipients; $i++) {
            $this->eligibleContact($user, $list);
        }

        $campaign = $this->draftFor($user, array_merge(['list_id' => $list->id], $overrides));

        $refused = app(CampaignLauncher::class)->launchNow($campaign);

        $this->assertNull($refused, 'The campaign should have launched: '.($refused?->summary() ?? ''));

        return $campaign->fresh();
    }

    /**
     * One worker pass, with exactly one send allowed.
     */
    private function sendOne(Campaign $campaign): void
    {
        Queue::fake();

        DB::table('campaigns')->where('id', $campaign->id)
            ->update(['next_send_at' => now()->subSecond()]);

        app(CampaignRunner::class)->run($campaign->fresh());
    }

    /**
     * Worker passes until nothing is left, rewinding the pace clock between them.
     */
    private function sendUntilEmpty(Campaign $campaign): void
    {
        for ($pass = 0; $pass < 20; $pass++) {
            $this->makeRetriesDue($campaign);

            DB::table('campaigns')->where('id', $campaign->id)
                ->update(['next_send_at' => now()->subMinute()]);

            $outcome = app(CampaignRunner::class)->run($campaign->fresh());

            if ($outcome->action === 'completed' || $outcome->action === 'stopped') {
                return;
            }
        }

        $this->fail('The campaign did not settle within the pass limit.');
    }

    private function makeRetriesDue(Campaign $campaign): void
    {
        DB::table('campaign_recipients')
            ->where('campaign_id', $campaign->id)
            ->update(['next_attempt_at' => now()->subMinute()]);
    }

    private function operationsFor(Campaign $campaign): CampaignOperations
    {
        $campaign = $campaign->fresh();

        return CampaignOperations::read($campaign, CampaignSummary::of($campaign, $campaign->recipientCounts()));
    }

    /**
     * @return list<string>
     */
    private function actionsFor(Campaign $campaign): array
    {
        return array_map(
            static fn ($action): string => $action->value,
            CampaignSummary::of($campaign, $campaign->recipientCounts())->actions(),
        );
    }
}

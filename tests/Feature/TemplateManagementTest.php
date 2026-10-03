<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Templates\PersonalisationToken;
use App\Domain\Templates\Template;
use App\Domain\Templates\TemplateStatus;
use App\Models\User;
use App\Support\Navigation\ProductNavigation;
use Tests\TestCase;

/**
 * Reusable message templates.
 *
 * Four properties are under test, and each one is a way this stage could quietly
 * do harm:
 *
 *   - **A template is content, not code.** Nothing in a body is compiled, and a
 *     placeholder nobody recognises is refused rather than sent to a recipient as
 *     literal text.
 *   - **One tenant's templates are invisible to another's.** Ids are sequential, so
 *     a cross-tenant read would be a successful guess rather than a loud mistake.
 *   - **Editing a template cannot rewrite what a campaign already copied.** The
 *     version counter is the whole mechanism, so it is tested through a snapshot
 *     rather than through the number alone.
 *   - **A preview cannot execute anything.** The sandbox is the guarantee; the
 *     markup stripping is tested too, because it is what makes the frame readable.
 */
class TemplateManagementTest extends TestCase
{
    // Pages

    public function test_the_template_index_renders_with_no_templates(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/templates')
            ->assertOk()
            ->assertSee('No templates yet')
            ->assertSee('New template');
    }

    public function test_the_template_index_lists_the_account_s_own_templates(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $mine = Template::factory()->for($user)->create(['name' => 'October update']);
        Template::factory()->for($other)->create(['name' => 'Somebody else\u{2019}s newsletter']);

        $this->actingAs($user)
            ->get('/templates')
            ->assertOk()
            ->assertSee('October update')
            ->assertDontSee('Somebody else');
    }

    public function test_the_new_template_page_renders(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/templates/new')
            ->assertOk()
            ->assertSee('New template')
            ->assertSee('{{unsubscribe_url}}');
    }

    public function test_template_pages_require_a_confirmed_address(): void
    {
        $this->actingAs(User::factory()->unverified()->create())
            ->get('/templates')
            ->assertRedirect(route('verification.notice'));
    }

    public function test_template_pages_require_authentication(): void
    {
        $this->get('/templates')->assertRedirect('/login');
        $this->get('/templates/new')->assertRedirect('/login');
    }

    // Creating

    public function test_an_account_can_create_a_template(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/templates', [
            'name' => 'October update',
            'subject' => 'What changed in October',
            'preheader' => 'Three things, in four minutes.',
            'html_body' => '<p>Hello {{first_name}}</p><a href="{{unsubscribe_url}}">Unsubscribe</a>',
            'text_body' => 'Hello {{first_name}}',
        ]);

        $template = Template::query()->firstOrFail();

        $response->assertRedirect(route('templates.show', $template));

        $this->assertSame($user->id, $template->user_id);
        $this->assertSame('October update', $template->name);
        $this->assertSame(1, $template->version);
        $this->assertSame(TemplateStatus::Ready, $template->status);
    }

    public function test_a_template_needs_a_subject_and_both_message_bodies(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/templates', [
                'name' => 'Incomplete',
                'subject' => '',
                'html_body' => '',
                'text_body' => '',
            ])
            ->assertSessionHasErrors(['subject', 'html_body', 'text_body']);

        $this->assertSame(0, Template::query()->count());
    }

    public function test_a_placeholder_the_platform_does_not_fill_in_is_refused(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/templates', [
                'name' => 'Typo',
                'subject' => 'Hello',
                'html_body' => '<p>Hello {{frist_name}}</p>',
                'text_body' => 'Hello {{frist_name}}',
            ])
            ->assertSessionHasErrors(['html_body', 'text_body']);

        $this->assertSame(0, Template::query()->count());
    }

    public function test_content_that_looks_like_an_attempt_to_run_code_is_refused(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/templates', [
                'name' => 'Sneaky',
                'subject' => 'Hello',
                'html_body' => '<p>{!! App::get(\'env\') !!}</p>',
                'text_body' => 'Hello',
            ])
            ->assertSessionHasErrors('html_body');

        $this->assertSame(0, Template::query()->count());
    }

    public function test_two_accounts_may_each_have_a_template_with_the_same_name(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        foreach ([$mine, $theirs] as $user) {
            $this->actingAs($user)->post('/templates', [
                'name' => 'October update',
                'subject' => 'Hello',
                'html_body' => '<p>Hello</p>',
                'text_body' => 'Hello',
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(2, Template::query()->count());
    }

    public function test_one_account_cannot_have_two_templates_with_the_same_name(): void
    {
        $user = User::factory()->create();

        Template::factory()->for($user)->create(['name' => 'October update']);

        $this->actingAs($user)->post('/templates', [
            'name' => 'October update',
            'subject' => 'Hello',
            'html_body' => '<p>Hello</p>',
            'text_body' => 'Hello',
        ])->assertSessionHasErrors('name');
    }

    // Editing and versioning

    public function test_an_account_can_edit_its_own_template(): void
    {
        $user = User::factory()->create();
        $template = Template::factory()->for($user)->create(['name' => 'October update']);

        $this->actingAs($user)
            ->put("/templates/{$template->id}", [
                'name' => 'October update',
                'subject' => 'What changed in October, revised',
                'preheader' => null,
                'html_body' => '<p>Hello {{first_name}}</p>',
                'text_body' => 'Hello {{first_name}}',
            ])
            ->assertRedirect(route('templates.show', $template));

        $this->assertSame('What changed in October, revised', $template->fresh()->subject);
    }

    public function test_editing_the_content_starts_a_new_version(): void
    {
        $user = User::factory()->create();
        $template = Template::factory()->for($user)->create();

        $this->actingAs($user)->put("/templates/{$template->id}", [
            'name' => $template->name,
            'subject' => $template->subject,
            'preheader' => $template->preheader,
            'html_body' => '<p>A completely different message.</p>',
            'text_body' => $template->text_body,
        ]);

        $this->assertSame(2, $template->fresh()->version);

        $this->actingAs($user)->put("/templates/{$template->id}", [
            'name' => $template->name,
            'subject' => $template->subject,
            'preheader' => $template->preheader,
            'html_body' => '<p>And another one.</p>',
            'text_body' => $template->text_body,
        ]);

        $this->assertSame(3, $template->fresh()->version);
    }

    public function test_saving_without_changing_the_content_does_not_start_a_new_version(): void
    {
        $user = User::factory()->create();
        $template = Template::factory()->for($user)->create();

        $this->actingAs($user)->put("/templates/{$template->id}", $template->only([
            'name',
            'subject',
            'preheader',
            'html_body',
            'text_body',
        ]));

        // A counter that moves on every save records how often a form was
        // submitted, which is not what "which content did this campaign send?"
        // needs answered.
        $this->assertSame(1, $template->fresh()->version);
    }

    public function test_content_a_campaign_already_copied_cannot_be_changed_by_a_later_edit(): void
    {
        $user = User::factory()->create();
        $template = Template::factory()->for($user)->create(['html_body' => '<p>The message as it was.</p>']);

        // What a campaign records when it starts: the content and the version it
        // copied, not a reference back to a mutable row.
        $copied = $template->snapshot();

        $this->actingAs($user)->put("/templates/{$template->id}", [
            'name' => $template->name,
            'subject' => $template->subject,
            'preheader' => $template->preheader,
            'html_body' => '<p>The message as it was rewritten afterwards.</p>',
            'text_body' => $template->text_body,
        ]);

        $this->assertSame('<p>The message as it was.</p>', $copied['html_body']);
        $this->assertSame(1, $copied['version']);
        $this->assertNotSame($copied['html_body'], $template->fresh()->html_body);
    }

    // Tenant isolation

    public function test_one_account_cannot_read_another_account_s_template(): void
    {
        $user = User::factory()->create();
        $template = Template::factory()->create(['name' => 'Somebody else\u{2019}s newsletter']);

        $this->actingAs($user)->get("/templates/{$template->id}")->assertNotFound();
        $this->actingAs($user)->get("/templates/{$template->id}/edit")->assertNotFound();
    }

    public function test_one_account_cannot_change_or_delete_another_account_s_template(): void
    {
        $user = User::factory()->create();
        $template = Template::factory()->create(['subject' => 'Not yours']);

        $payload = [
            'name' => 'Stolen',
            'subject' => 'Stolen',
            'html_body' => '<p>Stolen</p>',
            'text_body' => 'Stolen',
        ];

        $this->actingAs($user)->put("/templates/{$template->id}", $payload)->assertNotFound();
        $this->actingAs($user)->delete("/templates/{$template->id}")->assertNotFound();
        $this->actingAs($user)->post("/templates/{$template->id}/duplicate")->assertNotFound();

        $this->assertSame('Not yours', $template->fresh()->subject);
        $this->assertSame(1, Template::query()->count());
    }

    public function test_a_guest_is_sent_away_from_a_template_record(): void
    {
        $template = Template::factory()->create();

        $this->get("/templates/{$template->id}")->assertRedirect('/login');
    }

    // Copying and deleting

    public function test_a_template_can_be_copied(): void
    {
        $user = User::factory()->create();
        $template = Template::factory()->for($user)->create(['name' => 'October update']);

        $response = $this->actingAs($user)->post("/templates/{$template->id}/duplicate");

        $copy = Template::query()->whereKeyNot($template->id)->firstOrFail();

        $response->assertRedirect(route('templates.edit', $copy));

        $this->assertSame('October update (copy)', $copy->name);
        $this->assertSame($template->html_body, $copy->html_body);
        $this->assertSame($template->text_body, $copy->text_body);

        // A copy is a different document and starts its own history. Claiming it
        // is version 2 of the original would describe an edit that never happened.
        $this->assertSame(1, $copy->version);
        $this->assertSame(1, $template->fresh()->version);
    }

    public function test_copying_twice_does_not_produce_two_templates_with_the_same_name(): void
    {
        $user = User::factory()->create();
        $template = Template::factory()->for($user)->create(['name' => 'October update']);

        $this->actingAs($user)->post("/templates/{$template->id}/duplicate");
        $this->actingAs($user)->post("/templates/{$template->id}/duplicate");

        $names = Template::query()->orderBy('id')->pluck('name')->all();

        $this->assertSame(['October update', 'October update (copy)', 'October update (copy) 2'], $names);
    }

    public function test_copying_creates_a_record_and_is_not_reachable_by_a_get(): void
    {
        $user = User::factory()->create();
        $template = Template::factory()->for($user)->create();

        // A GET would let a prefetcher, a shared tab or an email client create
        // templates nobody asked for.
        $this->actingAs($user)
            ->get("/templates/{$template->id}/duplicate")
            ->assertMethodNotAllowed();

        $this->assertSame(1, Template::query()->count());
    }

    public function test_a_template_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $template = Template::factory()->for($user)->create();

        $this->actingAs($user)
            ->delete("/templates/{$template->id}")
            ->assertRedirect(route('templates.index'));

        $this->assertSame(0, Template::query()->count());
    }

    // The detail page

    public function test_the_detail_page_previews_the_message_in_a_locked_frame(): void
    {
        $user = User::factory()->create();

        $template = Template::factory()->for($user)->create([
            'name' => 'October update',
            'html_body' => '<p>Hello {{first_name}}</p><script>steal()</script>',
        ]);

        $response = $this->actingAs($user)->get("/templates/{$template->id}");

        $response->assertOk();
        $response->assertSee('October update', false);

        // The frame is the boundary: no allowances, so nothing in it can run.
        $response->assertSee('<iframe', false);
        $this->assertMatchesRegularExpression(
            '/<iframe[^>]*\bsandbox\b/i',
            $response->getContent() ?: '',
            'the preview must be rendered inside a sandboxed frame',
        );

        // And the stored markup is stripped before it reaches the frame, so the
        // script never renders as a script.
        $response->assertDontSee('<script>steal()</script>', false);
        $response->assertDontSee('steal()', false);
    }

    public function test_the_detail_page_fills_placeholders_with_examples(): void
    {
        $user = User::factory()->create();

        $template = Template::factory()->for($user)->create([
            'html_body' => '<p>Hello {{first_name}}</p>',
            'text_body' => 'Hello {{first_name}}',
        ]);

        $this->actingAs($user)
            ->get("/templates/{$template->id}")
            ->assertOk()
            ->assertSee('Hello Ada');
    }

    public function test_a_template_that_cannot_be_sent_says_why(): void
    {
        $user = User::factory()->create();

        $template = Template::factory()->for($user)->withUnknownPlaceholder()->create();

        $this->actingAs($user)
            ->get("/templates/{$template->id}")
            ->assertOk()
            ->assertSee('This template cannot be sent yet')
            ->assertSee('frist_name');
    }

    public function test_a_complete_template_says_it_is_ready(): void
    {
        $user = User::factory()->create();

        $template = Template::factory()->for($user)->create();

        $this->actingAs($user)
            ->get("/templates/{$template->id}")
            ->assertOk()
            ->assertDontSee('This template cannot be sent yet')
            ->assertSee(TemplateStatus::Ready->label());
    }

    // Navigation

    public function test_the_navigation_entry_points_at_a_real_template_route(): void
    {
        $templateEntry = collect(ProductNavigation::items())
            ->first(fn ($item): bool => $item->route === 'templates.index');

        $this->assertNotNull($templateEntry);
        $this->assertSame('Templates', $templateEntry->label);

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee(route('templates.index'), false);
    }

    public function test_templates_are_no_longer_a_placeholder_page(): void
    {
        // The staged shell said this. A real page must not, or an operator reading
        // the page would conclude the feature is absent.
        $this->actingAs(User::factory()->create())
            ->get('/templates')
            ->assertOk()
            ->assertDontSee('Not yet available');
    }

    // The placeholder vocabulary

    public function test_every_supported_placeholder_is_offered_to_the_customer(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/templates/new')
            ->assertOk()
            ->assertSee(PersonalisationToken::placeholders(), false);
    }
}

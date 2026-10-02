<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Extraction;
use App\Models\User;
use Tests\TestCase;

/**
 * Dashboard presentation.
 *
 * The dashboard is the first authenticated page and the one place where the
 * product describes itself. Two things are therefore worth protecting here:
 * that it never tells a customer something the application cannot do, and that
 * it never shows one account the work of another. The rest of this file is
 * ordinary rendering, but these two failures are the ones that would actually
 * matter in production.
 */
class DashboardScreenTest extends TestCase
{
    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_the_dashboard_renders_for_a_confirmed_account(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_the_dashboard_has_exactly_one_heading(): void
    {
        $content = (string) $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($content, '<h1'));
    }

    /**
     * An unconfirmed account must be told what to do and what it is waiting for,
     * rather than shown links that will bounce it straight back here.
     */
    public function test_an_unconfirmed_account_is_asked_to_confirm_first(): void
    {
        $content = (string) $this->actingAs(User::factory()->unverified()->create())
            ->get('/dashboard')
            ->assertOk()
            ->getContent();

        $main = $this->mainOf($content);

        $this->assertStringContainsString('Confirm your email address', $main);
        $this->assertStringContainsString('href="'.route('verification.notice').'"', $main);

        // Nothing behind the confirmation gate is offered as a next step.
        $this->assertStringNotContainsString('href="'.route('extractor.create').'"', $main);
        $this->assertStringNotContainsString('href="'.route('account.smtp.create').'"', $main);
        $this->assertStringNotContainsString('href="'.route('account.deliverability.index').'"', $main);
    }

    public function test_a_confirmed_account_sees_no_confirmation_prompt(): void
    {
        $main = $this->mainOf((string) $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->getContent());

        $this->assertStringNotContainsString('Confirm your email address', $main);
    }

    public function test_the_available_workflows_are_reachable_for_a_confirmed_account(): void
    {
        $main = $this->mainOf((string) $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->getContent());

        foreach ([
            'extractor.create',
            'extractor.history',
            'account.smtp.create',
            'account.smtp.index',
            'account.deliverability.index',
            'account.profile',
            'account.security',
        ] as $name) {
            $this->assertStringContainsString('href="'.route($name).'"', $main, "missing link to [{$name}]");
        }
    }

    /**
     * Extraction works. Saying otherwise on the home screen is worse than
     * omitting the section.
     */
    public function test_the_dashboard_stops_claiming_extraction_is_unavailable(): void
    {
        $main = $this->mainOf((string) $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->getContent());

        $this->assertStringNotContainsString('not yet available', $main);
        $this->assertStringNotContainsString('Extracting email addresses, or sending from this account, is not', $main);
    }

    /**
     * Pending areas may be named, but never as something to click.
     */
    public function test_pending_areas_are_named_but_not_offered_as_actions(): void
    {
        $main = $this->mainOf((string) $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->getContent());

        foreach (['campaigns.index', 'templates.index', 'files.index', 'analytics.index'] as $pending) {
            $this->assertStringNotContainsString('href="'.route($pending).'"', $main);
        }

        $this->assertStringContainsString('Coming soon', $main);
        $this->assertStringContainsString('still being built', $main);
    }

    /**
     * Nothing is asserted about delivery that the application cannot observe.
     */
    public function test_no_metric_the_product_cannot_measure_is_claimed(): void
    {
        $user = User::factory()->create();

        Extraction::factory()->count(2)->create(['user_id' => $user->id]);

        $main = $this->mainOf((string) $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->getContent());

        foreach ([
            'open rate', 'click-through', 'click rate', 'bounce rate', 'delivery rate',
            'inbox placement', 'guaranteed', 'reputation', 'unlimited', 'rotate IP',
        ] as $claim) {
            $this->assertStringNotContainsString($claim, $main);
        }
    }

    public function test_recent_activity_shows_only_the_signed_in_accounts_work(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();

        Extraction::factory()->create([
            'user_id' => $user->id,
            'name' => 'Website leads',
            'found_count' => 7,
        ]);

        Extraction::factory()->create([
            'user_id' => $stranger->id,
            'name' => 'Somebody elses list',
        ]);

        $main = $this->mainOf((string) $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->getContent());

        $this->assertStringContainsString('Website leads', $main);
        $this->assertStringContainsString('7', $main);
        $this->assertStringNotContainsString('Somebody elses list', $main);
        $this->assertStringNotContainsString($stranger->email, $main);
    }

    public function test_an_account_with_no_history_is_told_what_to_do_next(): void
    {
        $main = $this->mainOf((string) $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->getContent());

        $this->assertStringContainsString('No extractions yet', $main);
        $this->assertStringContainsString('href="'.route('extractor.create').'"', $main);
    }

    public function test_the_account_section_uses_human_labels_and_its_own_address(): void
    {
        $user = User::factory()->create();

        $main = $this->mainOf((string) $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->getContent());

        $this->assertStringContainsString($user->email, $main);
        $this->assertStringContainsString($user->role->label(), $main);

        // The stored value behind that label is not shown to a customer.
        $this->assertStringNotContainsString($user->role->value, $main);
    }

    public function test_the_dashboard_exposes_no_implementation_detail(): void
    {
        $main = $this->mainOf((string) $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->getContent());

        foreach ([
            'PendingFeatureController', 'CapabilitySubject', 'middleware', 'confirmed',
            'Illuminate\\', 'App\\', 'config(', 'Role::', 'php artisan', 'Laravel',
            'redirect()->', 'route(',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $main);
        }

        foreach (['{!!', '@php', '{{ $', '<?php'] as $marker) {
            $this->assertStringNotContainsString($marker, $main);
        }
    }

    /**
     * The page body, without the shared shell's navigation.
     *
     * The shell legitimately links to sections that are still being built; the
     * dashboard's own content must not.
     */
    private function mainOf(string $content): string
    {
        preg_match('/<main\b[^>]*>(.*)<\/main>/s', $content, $matches);

        return $matches[1] ?? '';
    }
}

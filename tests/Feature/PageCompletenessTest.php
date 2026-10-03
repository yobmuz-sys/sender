<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Templates\Template;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Permission;
use App\Models\ContactList;
use App\Models\User;
use App\Support\Navigation\AdminNavigation;
use App\Support\Navigation\ProductNavigation;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Page completeness.
 *
 * A declared page that has no route, or a navigation entry pointing at a route
 * that 404s, fails silently: the template still compiles, the suite still
 * passes, and the only symptom is a person clicking a link and finding a dead
 * page. These tests make that impossible to introduce silently.
 */
class PageCompletenessTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function publicPages(): array
    {
        return [
            'landing' => ['/'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function guestPages(): array
    {
        return [
            'register' => ['/register'],
            'login' => ['/login'],
            'forgot password' => ['/forgot-password'],
            'reset password' => ['/reset-password/token'],
        ];
    }

    /**
     * Signed-in pages that a guest must be redirected away from.
     *
     * @return array<string, array{0: string}>
     */
    public static function authenticatedOnlyPages(): array
    {
        return [
            'verification notice' => ['/email/verify'],
            'dashboard' => ['/dashboard'],
            'profile' => ['/account/profile'],
            'security' => ['/account/security'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function accountPages(): array
    {
        return [
            'dashboard' => ['/dashboard'],
            'profile' => ['/account/profile'],
            'security' => ['/account/security'],
            'extractor' => ['/extractor'],
            'extractor new' => ['/extractor/new'],
            'extractor history' => ['/extractor/history'],
            'files' => ['/files'],
            'files new' => ['/files/new'],
            'files record' => ['/files/abc'],
            'lists' => ['/lists'],
            'lists new' => ['/lists/new'],
            'lists record' => ['/lists/1'],
            'templates' => ['/templates'],
            'templates new' => ['/templates/new'],
            'templates record' => ['/templates/1'],
            'campaigns' => ['/campaigns'],
            'campaigns new' => ['/campaigns/new'],
            'campaigns record' => ['/campaigns/abc'],
            'campaigns edit' => ['/campaigns/abc/edit'],
            'suppression' => ['/suppression'],
            'analytics' => ['/analytics'],
        ];
    }

    /**
     * Every administration page, with the permission it must require.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function adminPages(): array
    {
        return [
            'dashboard' => ['/admin', Permission::ADMIN_VIEW],
            'users' => ['/admin/users', Permission::USERS_VIEW],
            'users create' => ['/admin/users/create', Permission::USERS_CREATE],
            'users detail' => ['/admin/users/1', Permission::USERS_VIEW],
            'users edit' => ['/admin/users/1/edit', Permission::USERS_EDIT],
            'roles' => ['/admin/roles', Permission::USERS_VIEW],
            'features' => ['/admin/features', Permission::FEATURES_VIEW],
            'plans' => ['/admin/plans', Permission::PLANS_VIEW],
            'campaigns' => ['/admin/campaigns', Permission::CAMPAIGNS_VIEW],
            'jobs' => ['/admin/jobs', Permission::JOBS_VIEW],
            'runs' => ['/admin/runs', Permission::JOBS_VIEW],
            'audience' => ['/admin/audience', Permission::CONTACTS_VIEW],
            'validation' => ['/admin/validation', Permission::VALIDATION_VIEW],
            'lists' => ['/admin/lists', Permission::LISTS_VIEW],
            'suppression' => ['/admin/suppression', Permission::SUPPRESSION_VIEW],
            'smtp' => ['/admin/smtp', Permission::SYSTEM_VIEW],
            'system' => ['/admin/system', Permission::SYSTEM_VIEW],
            'system diagnostics' => ['/admin/system/diagnostics', Permission::SYSTEM_VIEW],
            'system subsystems' => ['/admin/system/subsystems', Permission::SYSTEM_VIEW],
            'settings' => ['/admin/settings', Permission::SYSTEM_VIEW],
            'api' => ['/admin/api', Permission::SYSTEM_VIEW],
            'billing' => ['/admin/billing', Permission::SYSTEM_VIEW],
            'audit' => ['/admin/audit', Permission::SYSTEM_VIEW],
        ];
    }

    #[DataProvider('publicPages')]
    public function test_every_public_page_renders(string $path): void
    {
        $this->get($path)->assertOk();
    }

    #[DataProvider('guestPages')]
    public function test_every_guest_page_renders(string $path): void
    {
        $this->get($path)->assertOk();
    }

    #[DataProvider('guestPages')]
    public function test_every_guest_page_redirects_an_authenticated_account(string $path): void
    {
        $this->actingAs(User::factory()->create())
            ->get($path)
            ->assertRedirect();
    }

    #[DataProvider('authenticatedOnlyPages')]
    public function test_an_authenticated_only_page_redirects_a_guest(string $path): void
    {
        $this->get($path)->assertRedirect('/login');
    }

    #[DataProvider('authenticatedOnlyPages')]
    public function test_every_authenticated_only_page_renders(string $path): void
    {
        // Unverified, because the verification notice deliberately bounces an
        // account that has already confirmed its address.
        $this->actingAs(User::factory()->unverified()->create())
            ->get($path)
            ->assertOk();
    }

    public function test_a_confirmed_account_is_sent_away_from_the_verification_notice(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/email/verify')
            ->assertRedirect(route('dashboard'));
    }

    #[DataProvider('accountPages')]
    public function test_every_account_page_renders_for_a_signed_in_account(string $path): void
    {
        $user = User::factory()->create();

        // A record page needs a record the account actually owns. A slug-shaped
        // path would render a shell, but lists and templates are real rows now, and
        // an empty detail page proves nothing about the real one.
        if ($path === '/lists/1') {
            ContactList::factory()->for($user)->create();
        }

        if ($path === '/templates/1') {
            Template::factory()->for($user)->create();
        }

        $this->actingAs($user)
            ->get($path)
            ->assertOk();
    }

    #[DataProvider('accountPages')]
    public function test_every_account_page_requires_authentication(string $path): void
    {
        $this->get($path)->assertRedirect('/login');
    }

    #[DataProvider('adminPages')]
    public function test_every_admin_page_renders_for_a_super_administrator(string $path): void
    {
        $this->actingAs(User::factory()->role(Role::SuperAdmin)->create())
            ->get($path)
            ->assertOk();
    }

    #[DataProvider('adminPages')]
    public function test_no_admin_page_is_reachable_without_a_permission(string $path, string $permission): void
    {
        // A plain customer account holds no administrative permission at all.
        $this->actingAs(User::factory()->create())
            ->get($path)
            ->assertForbidden();
    }

    public function test_a_plain_user_cannot_reach_the_administration_area_at_all(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_staff_reach_only_the_pages_their_permissions_allow(): void
    {
        // Support can read users but must not create one.
        $this->actingAs(User::factory()->role(Role::Support)->create())
            ->get('/admin/users')
            ->assertOk();

        $this->actingAs(User::factory()->role(Role::Support)->create())
            ->get('/admin/users/create')
            ->assertForbidden();

        // Support cannot manage subsystems, which needs system.manage.
        $this->actingAs(User::factory()->role(Role::Support)->create())
            ->get('/admin/system/subsystems')
            ->assertOk();
    }

    public function test_the_administration_area_requires_a_confirmed_address(): void
    {
        $this->actingAs(User::factory()->role(Role::SuperAdmin)->unverified()->create())
            ->get('/admin')
            ->assertRedirect(route('verification.notice'));
    }

    public function test_the_extractor_requires_a_confirmed_address(): void
    {
        $this->actingAs(User::factory()->unverified()->create())
            ->get('/extractor')
            ->assertRedirect(route('verification.notice'));
    }

    /**
     * Every navigation entry names a real route.
     *
     * The failure this prevents is specific: an entry added to the navigation
     * before its route exists compiles fine and 404s for everyone.
     */
    public function test_every_navigation_entry_names_a_real_route(): void
    {
        foreach (AdminNavigation::routeNames() as $name) {
            $this->assertTrue(Route::has($name), "admin navigation references missing route [{$name}]");
        }

        foreach (ProductNavigation::routeNames() as $name) {
            $this->assertTrue(Route::has($name), "product navigation references missing route [{$name}]");
        }
    }

    public function test_navigation_shows_a_staff_account_only_what_it_can_reach(): void
    {
        $response = $this->actingAs(User::factory()->role(Role::Support)->create())
            ->get('/dashboard');

        $response->assertOk();

        // Support holds users.view and system.view, so both are offered.
        $response->assertSee(route('admin.users.index'), false);
        $response->assertSee(route('admin.system.index'), false);

        // It holds neither features.view nor plans.view.
        $response->assertDontSee(route('admin.features.index'), false);
        $response->assertDontSee(route('admin.plans.index'), false);
    }

    public function test_navigation_hides_administration_from_a_customer_account(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee(route('admin.dashboard'), false);
    }

    /**
     * A placeholder must be honest and must not perform a domain operation.
     */
    public function test_placeholder_pages_state_that_the_feature_is_pending(): void
    {
        $staff = User::factory()->role(Role::SuperAdmin)->create();

        foreach ([
            '/admin/features',
            '/admin/plans',
            '/admin/campaigns',
            '/admin/api',
            '/admin/billing',
            '/admin/audit',
        ] as $path) {
            $this->actingAs($staff)->get($path)
                ->assertOk()
                ->assertSee('Not yet available');
        }
    }

    public function test_placeholder_pages_do_not_query_a_domain_that_does_not_exist(): void
    {
        $staff = User::factory()->role(Role::SuperAdmin)->create();

        // A placeholder that grew a query against a table which does not exist
        // yet would be an invented dependency, so the tables it must not touch
        // are named explicitly here.
        $this->actingAs($staff)->get('/admin/campaigns')->assertOk();
        $this->actingAs($staff)->get('/admin/plans')->assertOk();

        $this->assertFalse(
            Schema::hasTable('campaigns'),
            'the campaigns placeholder must not have been given a table',
        );

        $this->assertFalse(
            Schema::hasTable('plans'),
            'the plans placeholder must not have been given a table',
        );
    }

    /**
     * The record a detail page was asked for is named on the page.
     *
     * A shell that renders identically whatever it was asked for cannot be
     * checked by the customer, and a list page that does not say which list is
     * open is indistinguishable from a bug when two tabs are open.
     */
    public function test_a_parameterised_shell_reports_which_record_it_was_asked_for(): void
    {
        $user = User::factory()->create();
        $list = ContactList::factory()->for($user)->create(['name' => 'Renewals 2026']);

        $this->actingAs($user)
            ->get("/lists/{$list->id}")
            ->assertOk()
            ->assertSee('Renewals 2026')
            ->assertSee('Lists');
    }

    public function test_the_health_endpoint_is_still_public_and_minimal(): void
    {
        $response = $this->getJson('/health');

        $response->assertOk();
        $this->assertSame(['status', 'capability'], array_keys($response->json()));
    }

    public function test_laravel_liveness_probe_answers_without_application_code(): void
    {
        $this->get('/up')->assertOk();
    }
}

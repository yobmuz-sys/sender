<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Enums\Role;
use App\Models\User;
use App\Support\Navigation\ProductNavigation;
use Tests\TestCase;

/**
 * The shared authenticated shell.
 *
 * This is the frame every signed-in page is rendered inside, so a defect here is
 * not a defect on one screen: it is on all of them at once, for customers and for
 * staff. The failure that prompted this coverage was a literal `@end` printed into
 * the navigation of every authenticated page, which is the kind of bug that a
 * status check on one screen would never catch.
 */
class AuthenticatedShellTest extends TestCase
{
    /**
     * Blade syntax must never reach a browser. Anything here appearing in rendered
     * output means a template was emitted instead of executed.
     *
     * @return list<string>
     */
    private static function bladeArtifacts(): array
    {
        return ['@end', '@if', '@endif', '@foreach', '@endforeach', '@auth', '@endauth', '@php', '{{ $', '{!!'];
    }

    public function test_the_shell_renders_for_a_customer(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_the_shell_renders_for_an_administrator(): void
    {
        $this->actingAs(User::factory()->role(Role::SuperAdmin)->create())
            ->get('/admin')
            ->assertOk();
    }

    /**
     * One shell, every kind of page: a customer page, the administration entry,
     * a child, a nested system page, an audit page and an account page. A defect in
     * the frame shows up on all of them, and so must its regression test.
     *
     * @return list<string>
     */
    private static function representativePages(): array
    {
        return [
            '/dashboard',
            '/extractor',
            '/admin',
            '/admin/users',
            '/admin/system',
            '/admin/system/diagnostics',
            '/admin/system/settings',
            '/admin/audit',
            '/account/profile',
            '/account/security',
            '/account/smtp',
            '/account/deliverability',
        ];
    }

    /**
     * The regression this file exists for.
     */
    public function test_no_authenticated_page_prints_blade_syntax(): void
    {
        $staff = User::factory()->role(Role::SuperAdmin)->create();

        foreach (self::representativePages() as $path) {
            $response = $this->actingAs($staff)->get($path);

            if ($response->getStatusCode() === 404) {
                continue;
            }

            $content = (string) $response->assertOk()->getContent();

            foreach (self::bladeArtifacts() as $artifact) {
                $this->assertStringNotContainsString(
                    $artifact,
                    $content,
                    "{$path} rendered Blade syntax [{$artifact}] to the browser",
                );
            }
        }
    }

    public function test_the_shell_provides_a_header_a_sidebar_and_an_account_control(): void
    {
        $content = (string) $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<header', $content);
        $this->assertStringContainsString('<aside', $content);
        $this->assertStringContainsString('<nav', $content);
        $this->assertStringContainsString('aria-label="Main"', $content);
        $this->assertStringContainsString('Account menu', $content);

        // Logging out stays a form submission, not a link that anyone can
        // accidentally follow, prefetch or crawl.
        $this->assertStringContainsString('method="POST" action="'.route('logout').'"', $content);
        $this->assertStringContainsString('name="_token"', $content);
    }

    /**
     * The shell must not depend on a JavaScript runtime it does not ship.
     */
    public function test_the_shell_needs_no_scripting_to_navigate(): void
    {
        $content = (string) $this->actingAs(User::factory()->role(Role::SuperAdmin)->create())
            ->get('/admin')
            ->assertOk()
            ->getContent();

        foreach (['x-data', 'x-show', 'x-cloak', '@click', 'x-on:', 'x-transition'] as $directive) {
            $this->assertStringNotContainsString($directive, $content);
        }

        // Both navigation surfaces are native disclosures, so they work with
        // scripting disabled.
        $this->assertGreaterThanOrEqual(2, substr_count($content, '<details'));
    }

    public function test_a_customer_is_not_offered_administration(): void
    {
        $content = (string) $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(route('admin.dashboard'), $this->navigationOf($content));
        $this->assertStringNotContainsString(route('admin.users.index'), $this->navigationOf($content));
        $this->assertStringNotContainsString('Administration', $content);
    }

    public function test_staff_are_offered_only_what_their_permissions_allow(): void
    {
        $support = $this->actingAs(User::factory()->role(Role::Support)->create())
            ->get('/dashboard')
            ->assertOk()
            ->getContent();

        // Support can read users and the system.
        $this->assertStringContainsString(route('admin.users.index'), $support);
        $this->assertStringContainsString(route('admin.system.index'), $support);

        // Support holds neither features.view nor plans.view.
        $this->assertStringNotContainsString(route('admin.features.index'), $support);
        $this->assertStringNotContainsString(route('admin.plans.index'), $support);
    }

    public function test_administration_is_labelled_as_its_own_area(): void
    {
        $content = (string) $this->actingAs(User::factory()->role(Role::SuperAdmin)->create())
            ->get('/dashboard')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Workspace', $content);
        $this->assertStringContainsString('Administration', $content);
        $this->assertStringContainsString('Account', $content);
    }

    public function test_the_current_page_is_marked_for_assistive_technology(): void
    {
        $content = (string) $this->actingAs(User::factory()->role(Role::SuperAdmin)->create())
            ->get('/admin/users')
            ->assertOk()
            ->getContent();

        preg_match('/<a[^>]*href="'.preg_quote(route('admin.users.index'), '/').'"[^>]*>/', $content, $matches);

        $this->assertNotEmpty($matches, 'the current page must be linked from the navigation');
        $this->assertStringContainsString('aria-current="page"', $matches[0]);
    }

    /**
     * A nested section opens itself when one of its pages is being viewed, so the
     * page a visitor is on can never be hidden behind a closed heading.
     */
    public function test_a_nested_administration_section_opens_for_its_own_page(): void
    {
        $staff = User::factory()->role(Role::SuperAdmin)->create();

        $content = (string) $this->actingAs($staff)->get('/admin/system/diagnostics')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<details[^>]*\sopen[^>]*>/', $content);

        preg_match('/<a[^>]*href="'.preg_quote(route('admin.system.diagnostics'), '/').'"[^>]*>/', $content, $matches);
        $this->assertNotEmpty($matches);
        $this->assertStringContainsString('aria-current="page"', $matches[0]);
    }

    /**
     * A section that leads somewhere else stays closed, so the open state means
     * something.
     */
    public function test_an_unrelated_nested_section_stays_closed(): void
    {
        $content = (string) $this->actingAs(User::factory()->role(Role::SuperAdmin)->create())
            ->get('/admin/users')
            ->assertOk()
            ->getContent();

        $this->assertSame(0, preg_match('/<details[^>]*\sopen[^>]*>/', $content));
    }

    /**
     * A section whose page is still being built says so in words, and stays
     * reachable: the product shows the surface and is honest about it rather than
     * pretending or hiding it.
     */
    public function test_a_pending_section_is_labelled_as_such(): void
    {
        $content = (string) $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Coming soon', $content);
    }

    public function test_the_navigation_leaks_no_internal_detail(): void
    {
        $content = (string) $this->actingAs(User::factory()->role(Role::SuperAdmin)->create())
            ->get('/dashboard')
            ->assertOk()
            ->getContent();

        $navigation = $this->navigationOf($content);

        foreach ([
            'Permission::', 'NavigationItem', 'AdminNavigation', 'ProductNavigation',
            'admin.system.diagnostics', 'account.smtp.create', 'App\\', 'Illuminate\\',
            'Laravel', 'route(', 'super_admin', 'admin.view',
        ] as $internal) {
            $this->assertStringNotContainsString($internal, $navigation);
        }
    }

    /**
     * The navigation and the pages it points at are read by the same person, so
     * they are not allowed to disagree about what a section is called.
     */
    public function test_the_mail_section_is_named_the_way_its_page_is(): void
    {
        // Which entry carries the label is a data question, so it is asked of the
        // data: the customer section still points at the same route under the name
        // the customer page uses.
        $mailEntry = collect(ProductNavigation::items())
            ->first(fn ($item): bool => $item->route === 'account.smtp.index');

        $this->assertNotNull($mailEntry, 'the customer mail section must remain in the navigation');
        $this->assertSame('Mail accounts', $mailEntry->label);
        $this->assertTrue($mailEntry->matchPrefix, 'the section still covers the pages beneath it');

        // And the same section is rendered under that name everywhere it appears.
        $content = (string) $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->getContent();

        $navigations = $this->navigationsOf($content);

        $this->assertCount(2, $navigations, 'the desktop sidebar and the mobile panel');

        foreach ($navigations as $navigation) {
            $this->assertStringContainsString('Mail accounts', $navigation);
            $this->assertStringNotContainsString('Mail transports', $navigation);
            $this->assertStringContainsString('href="'.route('account.smtp.index').'"', $navigation);
        }
    }

    /**
     * The navigation as rendered, without the page it wraps.
     */
    private function navigationOf(string $content): string
    {
        preg_match('/<nav\b[^>]*aria-label="Main"[^>]*>(.*?)<\/nav>/s', $content, $matches);

        return $matches[1] ?? '';
    }

    /**
     * Every rendered navigation surface, sidebar and mobile panel alike.
     *
     * @return list<string>
     */
    private function navigationsOf(string $content): array
    {
        preg_match_all('/<nav\b[^>]*aria-label="(?:Main|Mobile)"[^>]*>(.*?)<\/nav>/s', $content, $matches);

        return $matches[1];
    }
}

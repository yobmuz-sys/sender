<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\System\Enums\CapabilityStatus;
use App\Domain\Users\Enums\Role;
use App\Models\User;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_the_health_endpoint_reports_the_aggregate_verdict(): void
    {
        $response = $this->getJson('/health');

        $response->assertOk();
        $response->assertJsonStructure(['status', 'capability']);
    }

    public function test_the_health_endpoint_does_not_leak_host_detail(): void
    {
        $body = $this->getJson('/health')->getContent();

        $this->assertStringNotContainsString(base_path(), (string) $body);
        $this->assertStringNotContainsString(PHP_VERSION, (string) $body);
        $this->assertStringNotContainsString((string) config('app.key'), (string) $body);
    }

    public function test_the_health_endpoint_requires_no_authentication(): void
    {
        $this->assertGuest();

        $this->getJson('/health')->assertOk();
    }

    public function test_the_diagnostics_page_is_hidden_when_disabled(): void
    {
        config()->set('sender.diagnostics.enabled', false);

        // The page still renders, because a navigation link that 404s by
        // configuration teaches operators to distrust the navigation. What it
        // withholds is the detailed report, and it says so.
        $this->actingAs(User::factory()->role(Role::Support)->create())
            ->get(route('admin.system.diagnostics'))
            ->assertOk()
            ->assertSee('Detailed diagnostics are disabled')
            ->assertDontSee('Host checks');
    }

    public function test_the_diagnostics_page_requires_authentication(): void
    {
        $this->get('/diagnostics')->assertRedirect('/login');
        $this->get(route('admin.system.diagnostics'))->assertRedirect('/login');
    }

    public function test_the_diagnostics_page_renders_the_registry_output(): void
    {
        $user = User::factory()->role(Role::Support)->create();

        $this->actingAs($user)
            ->get(route('admin.system.diagnostics'))
            ->assertOk()
            ->assertSee('Capabilities')
            ->assertSee('Subsystems')
            ->assertSee('Scheduled processing')
            ->assertSee('Unknown');
    }

    public function test_the_diagnostics_page_agrees_with_the_health_endpoint(): void
    {
        $user = User::factory()->role(Role::Support)->create();

        // /health publishes the raw status; the page shows the same status as
        // a human label. Both must come from the one registry.
        //
        // This previously passed for the wrong reason: the page showed the
        // report's own aggregate, which is a different calculation and reported
        // UNKNOWN, while "Unknown" also appeared in the per-subject badges.
        $capability = CapabilityStatus::from(
            $this->getJson('/health')->json('capability'),
        );

        $this->actingAs($user)
            ->get(route('admin.system.diagnostics'))
            ->assertOk()
            ->assertSee($capability->label());
    }

    public function test_the_health_endpoint_stays_ok_while_degraded(): void
    {
        config()->set('sender.capabilities.required', []);

        // DEGRADED is operational. Only UNAVAILABLE makes the platform
        // unserviceable, so this must not become a 503.
        $response = $this->getJson('/health');

        $this->assertContains(
            $response->json('capability'),
            ['READY', 'DEGRADED', 'UNKNOWN'],
        );
        $response->assertOk();
    }
}

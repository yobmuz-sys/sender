<?php

declare(strict_types=1);

namespace Tests\Feature;

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

        $this->actingAs(User::factory()->role(Role::Support)->create())
            ->get('/diagnostics')
            ->assertNotFound();
    }

    public function test_the_diagnostics_page_requires_authentication(): void
    {
        $this->get('/diagnostics')->assertRedirect('/login');
    }
}

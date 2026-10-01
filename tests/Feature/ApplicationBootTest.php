<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class ApplicationBootTest extends TestCase
{
    public function test_the_application_boots_with_a_configured_environment(): void
    {
        $this->assertTrue($this->app->isBooted());

        $this->assertNotSame('', config('app.key'), 'APP_KEY must be set for encryption to work.');
        $this->assertSame('testing', config('app.env'));
    }

    public function test_configuration_uses_only_shared_hosting_drivers(): void
    {
        $this->assertNotContains(config('session.driver'), ['redis', 'memcached']);
        $this->assertNotContains(config('queue.default'), ['redis', 'sqs', 'beanstalkd']);
        $this->assertNotContains(config('cache.default'), ['redis', 'memcached']);
    }

    public function test_the_public_home_page_responds(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_the_liveness_probe_responds(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_the_application_name_is_configured(): void
    {
        $this->assertSame('Sender', config('app.name'));
    }
}

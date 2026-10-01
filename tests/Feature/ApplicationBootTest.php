<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Logging\RedactSensitiveDataProcessor;
use Illuminate\Log\Logger as LaravelLogger;
use Illuminate\Support\Facades\Log;
use Monolog\Logger as MonologLogger;
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

    public function test_configured_log_channels_redact_sensitive_context(): void
    {
        config([
            'logging.channels.slack.url' => 'https://example.invalid/webhook',
            'logging.channels.papertrail.handler_with.host' => '127.0.0.1',
            'logging.channels.papertrail.handler_with.port' => 514,
        ]);

        foreach (['single', 'daily', 'slack', 'papertrail', 'stderr', 'syslog', 'errorlog'] as $channel) {
            Log::forgetChannel($channel);

            $writer = Log::channel($channel);
            if (! $writer instanceof LaravelLogger) {
                self::fail("The {$channel} channel did not resolve to Laravel's logger.");
            }

            $logger = $writer->getLogger();
            if (! $logger instanceof MonologLogger) {
                self::fail("The {$channel} channel did not resolve to Monolog.");
            }

            $processors = $logger->getProcessors();
            $hasRedactor = in_array(true, array_map(
                static fn (object $processor): bool => $processor instanceof RedactSensitiveDataProcessor,
                $processors,
            ), true);

            $processorTypes = implode(', ', array_map('get_debug_type', $processors));
            $handlerTypes = implode(', ', array_map('get_debug_type', $logger->getHandlers()));

            $this->assertTrue(
                $hasRedactor,
                sprintf(
                    'The %s channel must redact sensitive context. Writer: %s; logger: %s (%s); handlers: %s; configured processors: %s; actual processors: %s',
                    $channel,
                    get_debug_type($writer),
                    get_debug_type($logger),
                    $logger->getName(),
                    $handlerTypes,
                    implode(', ', config("logging.channels.{$channel}.processors", [])),
                    $processorTypes,
                ),
            );
        }
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

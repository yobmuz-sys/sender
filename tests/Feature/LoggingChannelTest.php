<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Logging\RedactSensitiveDataProcessor;
use App\Support\Logging\RedactSensitiveDataTap;
use Illuminate\Log\Logger as LaravelLogger;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\NullHandler;
use Monolog\Logger as MonologLogger;
use Tests\TestCase;

/**
 * The Stage 1 defect was configuration that looked valid and failed at
 * runtime, and it survived a green suite because nothing ever wrote a record
 * through the channels. This test closes that gap: it resolves each channel,
 * writes a record carrying a secret, and asserts the redaction actually
 * happened.
 *
 * A channel that cannot accept a record on this host must fail here, not in
 * production error handling.
 */
class LoggingChannelTest extends TestCase
{
    /**
     * Channels whose transport is a local file, which can be written to and read
     * back safely. These get a full write-and-verify cycle; every other channel
     * is covered for construction and redaction wiring only.
     *
     * @var list<string>
     */
    private const WRITABLE_CHANNELS = ['single', 'daily'];

    /**
     * The Monolog constructor arguments each file handler actually declares.
     *
     * Supplying an argument a handler does not declare raises "unknown named
     * parameter", which is precisely the class of defect these tests exist to
     * catch, so the test supplies exactly what each handler needs.
     *
     * @var array<string, array<string, mixed>>
     */
    private const FILE_HANDLER_ARGS = [
        'single' => ['stream' => '$path'],
        'daily' => ['filename' => '$path', 'maxFiles' => 0],
    ];

    private string $logFile = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = storage_path('framework/testing/channel-probe.log');
        @unlink($this->logFile);
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);

        parent::tearDown();
    }

    public function test_every_file_backed_channel_accepts_a_record_and_redacts_it(): void
    {
        foreach (self::WRITABLE_CHANNELS as $channel) {
            $this->clearProbeFiles();

            $this->pointChannelAtProbeFile($channel);

            $this->monologFor($channel)->warning('probe', [
                'password' => 'super-secret-value',
                'user' => 'ops',
            ]);

            $written = $this->writtenProbeFile();

            $this->assertNotNull($written, "the {$channel} channel wrote nothing");

            $contents = (string) file_get_contents($written);

            $this->assertStringNotContainsString(
                'super-secret-value',
                $contents,
                "the {$channel} channel leaked a credential into the log file",
            );
            $this->assertStringContainsString('[redacted]', $contents, "the {$channel} channel did not redact");
            $this->assertStringContainsString('ops', $contents, "the {$channel} channel lost ordinary context");
        }
    }

    public function test_redaction_runs_before_placeholder_interpolation(): void
    {
        $this->clearProbeFiles();
        $this->pointChannelAtProbeFile('single');

        // PsrLogMessageProcessor interpolates context into the message text, so
        // if redaction ran afterwards the secret would already be in the string.
        $this->monologFor('single')->warning('value is [:password]', ['password' => 'interpolated-secret']);

        $written = $this->writtenProbeFile();
        $this->assertNotNull($written);

        $contents = (string) file_get_contents($written);

        $this->assertStringNotContainsString('interpolated-secret', $contents);
        $this->assertStringContainsString('[redacted]', $contents);
    }

    /**
     * The rotating handler appends a date suffix to the filename, so the file
     * a channel actually wrote is not necessarily the path it was given.
     */
    private function writtenProbeFile(): ?string
    {
        $files = glob(dirname($this->logFile).'/channel-probe*.log');

        return $files === false || $files === [] ? null : $files[0];
    }

    private function clearProbeFiles(): void
    {
        foreach ((array) glob(dirname($this->logFile).'/channel-probe*.log') as $file) {
            @unlink((string) $file);
        }
    }

    public function test_every_configured_channel_builds_and_redacts(): void
    {
        foreach (array_keys(config('logging.channels')) as $channel) {
            if ($channel === 'stack' || $channel === 'emergency') {
                continue; // composed from other channels, or a Laravel fallback
            }

            if ($channel === 'null') {
                $this->assertInstanceOf(NullHandler::class, $this->monologFor($channel)->getHandlers()[0]);

                continue; // discards everything; there is nothing to redact
            }

            Log::forgetChannel($channel);

            $logger = $this->monologFor($channel);

            $this->assertNotEmpty($logger->getHandlers(), "the {$channel} channel built no handler");

            foreach ($logger->getHandlers() as $handler) {
                $this->assertInstanceOf(HandlerInterface::class, $handler);
            }

            // Redaction is not optional. Drivers that ignore `processors` use a
            // tap instead, so either mechanism counts — but silence does not.
            $this->assertTrue(
                $this->redacts($channel, $logger),
                "the {$channel} channel has neither a redaction processor nor a redaction tap",
            );
        }
    }

    private function redacts(string $channel, MonologLogger $logger): bool
    {
        foreach ($logger->getProcessors() as $processor) {
            if ($processor instanceof RedactSensitiveDataProcessor) {
                return true;
            }
        }

        return in_array(
            RedactSensitiveDataTap::class,
            (array) config("logging.channels.{$channel}.tap", []),
            true,
        );
    }

    private function pointChannelAtProbeFile(string $channel): void
    {
        $arguments = array_map(
            fn (mixed $value): mixed => $value === '$path' ? $this->logFile : $value,
            self::FILE_HANDLER_ARGS[$channel],
        );

        config(["logging.channels.{$channel}.handler_with" => $arguments]);
        Log::forgetChannel($channel);
    }

    private function monologFor(string $channel): MonologLogger
    {
        $writer = Log::channel($channel);

        $this->assertInstanceOf(LaravelLogger::class, $writer, "the {$channel} channel did not resolve");

        return $writer->getLogger();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\System\Runs\RunRecorder;
use App\Domain\System\Runs\RunStatus;
use App\Domain\System\Runs\ScheduledRun;
use Tests\TestCase;

/**
 * A run record is the operational evidence cron health is derived from, so it
 * has to distinguish outcomes rather than merely record that something happened.
 */
class ScheduledRunTest extends TestCase
{
    public function test_a_run_is_recorded_as_running_before_it_finishes(): void
    {
        $run = app(RunRecorder::class)->start('sender:heartbeat');

        $this->assertSame(RunStatus::Running, $run->status);
        $this->assertNull($run->finished_at);
        $this->assertNull($run->duration_ms);
    }

    public function test_a_completed_run_records_its_outcome_and_duration(): void
    {
        $recorder = app(RunRecorder::class);

        $run = $recorder->succeed($recorder->start('sender:heartbeat'), processed: 12, failed: 3);

        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertIsInt($run->duration_ms);
        $this->assertSame(12, $run->processed_count);
        $this->assertSame(3, $run->failed_count);
    }

    public function test_a_failed_run_preserves_why_it_failed(): void
    {
        $recorder = app(RunRecorder::class);

        $run = $recorder->fail($recorder->start('sender:heartbeat'), 'host unreachable');

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('host unreachable', $run->error);
        $this->assertFalse($run->succeeded());
    }

    public function test_a_failure_message_cannot_persist_a_credential(): void
    {
        $recorder = app(RunRecorder::class);

        // Error text often quotes the configuration that caused the failure,
        // including a password, so it goes through the same redaction as logs.
        $run = $recorder->fail(
            $recorder->start('sender:heartbeat'),
            'authentication rejected for user "ops" with password=hunter2 using api_key=abcdef123456',
        );

        $this->assertStringNotContainsString('hunter2', (string) $run->error);
        $this->assertStringNotContainsString('abcdef123456', (string) $run->error);
        $this->assertStringContainsString('[redacted]', (string) $run->error);
    }

    public function test_a_failure_message_is_truncated_rather_than_stored_whole(): void
    {
        $recorder = app(RunRecorder::class);

        $run = $recorder->fail($recorder->start('sender:heartbeat'), str_repeat('x', 5000));

        $this->assertLessThanOrEqual(1000, mb_strlen((string) $run->error));
    }

    public function test_run_history_is_ordered_newest_first(): void
    {
        $recorder = app(RunRecorder::class);

        $recorder->succeed($recorder->start('sender:heartbeat'));
        $this->travel(1)->minutes();
        $recorder->succeed($recorder->start('sender:heartbeat'));
        $this->travel(1)->minutes();
        $recorder->succeed($recorder->start('sender:heartbeat'));

        $recent = $recorder->recent('sender:heartbeat');

        $this->assertCount(3, $recent);
        $this->assertTrue($recent[0]->started_at->greaterThan($recent[2]->started_at));
    }

    public function test_runs_of_other_commands_are_not_mixed_in(): void
    {
        $recorder = app(RunRecorder::class);

        $recorder->succeed($recorder->start('sender:heartbeat'));
        $recorder->succeed($recorder->start('sender:something-else'));

        $this->assertSame(1, ScheduledRun::query()->where('command', 'sender:heartbeat')->count());
        $this->assertSame('sender:heartbeat', $recorder->latest('sender:heartbeat')?->command);
    }

    public function test_the_latest_run_survives_a_cache_clear(): void
    {
        $this->recordRun();

        $this->artisan('cache:clear')->assertSuccessful();

        // The evidence is durable. An earlier design kept this in the cache, so
        // `cache:clear` silently reset cron to UNKNOWN.
        $this->assertTrue(app(RunRecorder::class)->isFresh('sender:work'));
    }

    public function test_freshness_requires_a_recent_success(): void
    {
        $recorder = app(RunRecorder::class);

        $recorder->succeed($recorder->start('sender:heartbeat'));
        $this->assertTrue($recorder->isFresh('sender:heartbeat'));

        $this->travel(2)->hours();
        $this->assertFalse($recorder->isFresh('sender:heartbeat'));

        // A newer failure supersedes an older success.
        $recorder->fail($recorder->start('sender:heartbeat'), 'boom');
        $this->assertFalse($recorder->isFresh('sender:heartbeat'));
    }

    public function test_the_run_record_is_append_only(): void
    {
        $this->recordRun();

        $attributes = ScheduledRun::query()->firstOrFail()->getAttributes();

        $this->assertArrayNotHasKey('created_at', $attributes);
        $this->assertArrayNotHasKey('updated_at', $attributes);
        $this->assertArrayHasKey('started_at', $attributes);
    }
}

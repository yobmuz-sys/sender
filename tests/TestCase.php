<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\System\Runs\RunRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // The suite runs against an in-memory SQLite database, so every test starts
    // from a freshly migrated schema. Nothing in the suite touches the
    // developer's real MySQL database.
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The suite asserts on HTTP behaviour, not on the asset pipeline. Not
        // requiring a Vite build keeps `php artisan test` runnable on a clean
        // checkout with no Node step.
        $this->withoutVite();
    }

    /**
     * Record one successful scheduled run, so tests that need cron to be
     * healthy do not each have to know how that evidence is stored.
     *
     * Defaults to the worker rather than the heartbeat: the cron verdict is
     * derived from `sender:work` alone, so a test that records only a
     * heartbeat would be asserting against a signal the platform no longer
     * treats as proof.
     */
    protected function recordRun(string $command = 'sender:work'): void
    {
        $recorder = app(RunRecorder::class);

        $recorder->succeed($recorder->start($command));
    }
}

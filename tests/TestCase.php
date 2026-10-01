<?php

declare(strict_types=1);

namespace Tests;

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
}

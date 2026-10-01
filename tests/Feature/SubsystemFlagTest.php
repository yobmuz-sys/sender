<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\System\Enums\Subsystem;
use App\Domain\System\Flags\SubsystemFlagRegistry;
use Tests\TestCase;

/**
 * Safe mode, emergency disablement and subsystem toggles must be one
 * mechanism. These tests also pin the durability requirement: an operator's
 * stop must survive a cache clear, because a cache-cleared kill switch that
 * silently re-enables a subsystem is worse than no kill switch at all.
 */
class SubsystemFlagTest extends TestCase
{
    public function test_subsystems_default_to_enabled(): void
    {
        $flags = app(SubsystemFlagRegistry::class);

        foreach (Subsystem::cases() as $subsystem) {
            $this->assertTrue($flags->enabled($subsystem), "{$subsystem->value} should default to enabled");
        }
    }

    public function test_an_operator_can_disable_and_re_enable_a_subsystem(): void
    {
        $flags = app(SubsystemFlagRegistry::class);

        $flags->disable(Subsystem::UrlFetch);
        $this->assertFalse($flags->enabled(Subsystem::UrlFetch));

        $flags->enable(Subsystem::UrlFetch);
        $this->assertTrue($flags->enabled(Subsystem::UrlFetch));
    }

    public function test_disabling_one_subsystem_does_not_affect_another(): void
    {
        $flags = app(SubsystemFlagRegistry::class);

        $flags->disable(Subsystem::Smtp);

        $this->assertFalse($flags->enabled(Subsystem::Smtp));
        $this->assertTrue($flags->enabled(Subsystem::UrlFetch));
        $this->assertTrue($flags->enabled(Subsystem::Cron));
    }

    public function test_a_disabled_subsystem_survives_a_cache_clear(): void
    {
        app(SubsystemFlagRegistry::class)->disable(Subsystem::Smtp);

        $this->artisan('cache:clear')->assertSuccessful();

        $this->assertFalse(
            app(SubsystemFlagRegistry::class)->enabled(Subsystem::Smtp),
            'an operator stop must not be undone by clearing the cache',
        );
    }

    public function test_reset_restores_the_configured_default(): void
    {
        $flags = app(SubsystemFlagRegistry::class);
        $flags->disable(Subsystem::Smtp);
        $flags->reset(Subsystem::Smtp);

        $this->assertTrue($flags->enabled(Subsystem::Smtp));
    }

    public function test_flags_are_persisted_in_system_settings(): void
    {
        app(SubsystemFlagRegistry::class)->disable(Subsystem::Cron);

        $this->assertDatabaseHas('system_settings', [
            'key' => 'subsystem.cron.enabled',
        ]);
    }
}

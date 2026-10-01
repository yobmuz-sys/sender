<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\System\Capabilities\Availability;
use App\Domain\System\Capabilities\AvailabilityResolver;
use App\Domain\System\Entitlements\DenyAllEntitlement;
use App\Domain\System\Entitlements\Entitlement;
use App\Domain\System\Enums\AvailabilityReason;
use App\Domain\System\Enums\AvailabilityState;
use App\Domain\System\Enums\CapabilityStatus;
use App\Domain\System\Enums\EntitlementStatus;
use App\Domain\System\Enums\Subsystem;
use App\Domain\System\Flags\SubsystemFlagRegistry;
use Tests\TestCase;

/**
 * Capability, operator flag and entitlement are three independent inputs.
 * These tests pin that they are composed in one place and that each can block
 * an operation on its own, without any of them being folded into the others.
 *
 * Cron is used as the sample subsystem because its capability state can be
 * moved through every value deterministically by recording or withholding a
 * heartbeat.
 */
class AvailabilityTest extends TestCase
{
    public function test_the_default_entitlement_denies_everything(): void
    {
        $entitlement = app(Entitlement::class);

        $this->assertInstanceOf(DenyAllEntitlement::class, $entitlement);
        $this->assertFalse($entitlement->allows('cron'));
        $this->assertSame(EntitlementStatus::NotEntitled, $entitlement->status('anything'));
    }

    public function test_an_unentitled_subsystem_is_blocked_for_entitlement_not_capability(): void
    {
        $this->recordRun();

        $availability = $this->resolve();

        $this->assertFalse($availability->allowed());
        $this->assertSame(AvailabilityState::Blocked, $availability->state);
        $this->assertSame(AvailabilityReason::NotEntitled, $availability->reason);

        // The capability itself is fine; only the account is not permitted.
        // Collapsing these two facts is what produces misleading diagnostics.
        $this->assertSame(CapabilityStatus::Ready, $availability->capability);
    }

    public function test_an_operator_stop_blocks_the_operation(): void
    {
        app(SubsystemFlagRegistry::class)->disable(Subsystem::Cron);

        $availability = $this->resolve();

        $this->assertSame(AvailabilityState::Blocked, $availability->state);
        $this->assertSame(AvailabilityReason::SubsystemDisabled, $availability->reason);
        $this->assertFalse($availability->subsystemEnabled);
    }

    public function test_an_entitled_and_measured_subsystem_is_available(): void
    {
        $this->recordRun();
        $this->app->instance(Entitlement::class, $this->entitledTo('cron'));

        $availability = $this->resolve();

        $this->assertTrue($availability->allowed());
        $this->assertSame(AvailabilityState::Available, $availability->state);
        $this->assertNull($availability->reason);
        $this->assertSame(EntitlementStatus::Entitled, $availability->entitlement);
    }

    public function test_an_unmeasured_capability_is_unverified_rather_than_blocked(): void
    {
        $this->app->instance(Entitlement::class, $this->entitledTo('smtp'));

        $availability = app(AvailabilityResolver::class)->resolve(Subsystem::Smtp);

        // UNKNOWN means "not established", which is not the same as "broken".
        // Only the consuming requirement may turn that into a refusal.
        $this->assertTrue($availability->allowed());
        $this->assertTrue($availability->unverified());
        $this->assertSame(AvailabilityState::Unverified, $availability->state);
        $this->assertNull($availability->reason);
        $this->assertSame(CapabilityStatus::Unknown, $availability->capability);
    }

    public function test_every_registered_subsystem_resolves(): void
    {
        $this->assertCount(
            count(Subsystem::cases()),
            app(AvailabilityResolver::class)->resolveAll(),
        );
    }

    private function resolve(): Availability
    {
        return app(AvailabilityResolver::class)->resolve(Subsystem::Cron);
    }

    /**
     * @param  list<string>  $grants
     */
    private function entitledTo(string ...$grants): Entitlement
    {
        $allowed = array_flip($grants);

        return new class($allowed) implements Entitlement
        {
            /**
             * @param  array<string, int>  $allowed
             */
            public function __construct(private readonly array $allowed) {}

            public function status(string $feature): EntitlementStatus
            {
                return isset($this->allowed[$feature])
                    ? EntitlementStatus::Entitled
                    : EntitlementStatus::NotEntitled;
            }

            public function allows(string $feature): bool
            {
                return isset($this->allowed[$feature]);
            }
        };
    }
}

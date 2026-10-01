<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\System\Capabilities\CapabilityRegistry;
use App\Domain\System\Enums\CapabilityStatus;
use App\Domain\System\Enums\CapabilitySubject;
use Tests\TestCase;

/**
 * The registry is the layer application code consumes; the inspector is the
 * layer that measures. These tests pin the difference between "measured and
 * fine", "not established", and "known broken".
 */
class CapabilityRegistryTest extends TestCase
{
    public function test_a_measured_capability_is_reported_ready(): void
    {
        $registry = app(CapabilityRegistry::class);

        $this->assertSame(CapabilityStatus::Ready, $registry->status(CapabilitySubject::Storage));
        $this->assertTrue($registry->isAvailable(CapabilitySubject::Storage));
    }

    public function test_a_capability_nobody_has_measured_is_unknown_not_ready(): void
    {
        $registry = app(CapabilityRegistry::class);

        // No SMTP or URL-fetch code exists yet, so nothing establishes them.
        $this->assertSame(CapabilityStatus::Unknown, $registry->status(CapabilitySubject::Smtp));
        $this->assertSame(CapabilityStatus::Unknown, $registry->status(CapabilitySubject::UrlFetch));

        $this->assertFalse(
            $registry->isAvailable(CapabilitySubject::Smtp),
            'an unmeasured capability must never be reported as usable',
        );
    }

    public function test_a_loading_extension_does_not_imply_a_capability(): void
    {
        $registry = app(CapabilityRegistry::class);

        // OpenSSL is loaded on every test host, yet SMTP is still unverified.
        $this->assertContains('ext: openssl', array_keys($registry->report()->asMap()));
        $this->assertSame(CapabilityStatus::Unknown, $registry->status(CapabilitySubject::Smtp));
    }

    public function test_an_unrequired_unknown_capability_does_not_change_the_overall_verdict(): void
    {
        config()->set('sender.capabilities.required', []);

        $this->assertNotSame(CapabilityStatus::Unknown, app(
            CapabilityRegistry::class
        )->overall());
    }

    public function test_a_required_unknown_capability_does_change_the_overall_verdict(): void
    {
        config()->set('sender.capabilities.required', [CapabilitySubject::Smtp->value]);

        $this->assertSame(CapabilityStatus::Unknown, app(
            CapabilityRegistry::class
        )->overall());
    }

    public function test_status_merge_keeps_the_worst_measured_result(): void
    {
        $this->assertSame(CapabilityStatus::Unavailable, CapabilityStatus::Ready->merge(CapabilityStatus::Unavailable));
        $this->assertSame(CapabilityStatus::Degraded, CapabilityStatus::Ready->merge(CapabilityStatus::Degraded));
        $this->assertSame(CapabilityStatus::Ready, CapabilityStatus::Ready->merge(CapabilityStatus::Ready));

        // UNKNOWN outranks DEGRADED: an unverified dependency is harder to plan
        // around than a known constraint.
        $this->assertSame(CapabilityStatus::Unknown, CapabilityStatus::Degraded->merge(CapabilityStatus::Unknown));
    }

    public function test_only_ready_counts_as_usable(): void
    {
        $this->assertTrue(CapabilityStatus::Ready->isUsable());
        $this->assertFalse(CapabilityStatus::Degraded->isUsable());
        $this->assertFalse(CapabilityStatus::Unknown->isUsable());
        $this->assertFalse(CapabilityStatus::Unavailable->isUsable());
    }
}

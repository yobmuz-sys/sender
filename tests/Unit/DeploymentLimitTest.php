<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\System\Enums\DeploymentLimit;
use Tests\TestCase;

class DeploymentLimitTest extends TestCase
{
    public function test_every_limit_resolves_to_a_positive_configured_value(): void
    {
        foreach (DeploymentLimit::cases() as $limit) {
            $this->assertGreaterThan(0, $limit->value(), "{$limit->value} is not configured");
        }
    }

    public function test_byte_limits_render_with_a_unit(): void
    {
        $this->assertMatchesRegularExpression('/^\d+(\.\d+)?[BKMGT]$/', DeploymentLimit::MaxUploadBytes->humanValue());
        $this->assertMatchesRegularExpression('/^\d+(\.\d+)?[BKMGT]$/', DeploymentLimit::MaxStorageBytes->humanValue());
    }

    public function test_count_limits_render_as_plain_numbers(): void
    {
        $this->assertSame((string) DeploymentLimit::MaxCampaignRecipients->value(), DeploymentLimit::MaxCampaignRecipients->humanValue());
    }

    public function test_a_limit_can_be_overridden_per_deployment(): void
    {
        config()->set('sender.deployment_limits.max_job_batch_size', 42);

        $this->assertSame(42, DeploymentLimit::MaxJobBatchSize->value());
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\System\Enums;

use App\Support\Bytes;

/**
 * Every resource boundary the operator can configure, resolved from
 * config/sender.php -> deployment_limits.
 *
 * Deliberately distinct from three neighbouring concepts:
 *
 *   requirements        what the host must provide
 *   deployment_limits   what this installation permits   (this enum)
 *   entitlements        what an account is granted        (later stage)
 *   usage               what an account has consumed      (later stage)
 *
 * Business logic asks this enum rather than reading config or embedding a
 * literal, so a deployment can change a ceiling without touching the code that
 * enforces it.
 */
enum DeploymentLimit: string
{
    case MaxUploadBytes = 'max_upload_bytes';
    case MaxTextInputBytes = 'max_text_input_bytes';
    case MaxUrlsPerRequest = 'max_urls_per_request';
    case MaxWorkerRuntimeSeconds = 'max_worker_runtime_seconds';
    case MaxJobBatchSize = 'max_job_batch_size';
    case MaxJobAttempts = 'max_job_attempts';
    case MaxStorageBytes = 'max_storage_bytes';
    case MaxApiRequestsPerHour = 'max_api_requests_per_hour';
    case MaxCampaignRecipients = 'max_campaign_recipients';

    /**
     * The configured ceiling for this limit.
     */
    public function value(): int
    {
        return (int) config('sender.deployment_limits.'.$this->value, 0);
    }

    /**
     * The configured value rendered for display, in bytes where applicable.
     */
    public function humanValue(): string
    {
        return match ($this) {
            self::MaxUploadBytes,
            self::MaxTextInputBytes,
            self::MaxStorageBytes => Bytes::humanize($this->value()),
            default => (string) $this->value(),
        };
    }
}

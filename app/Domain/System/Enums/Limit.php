<?php

declare(strict_types=1);

namespace App\Domain\System\Enums;

use App\Support\Bytes;

/**
 * Every resource boundary in the application, resolved from config/sender.php.
 *
 * Business logic asks the enum rather than reading config or embedding a
 * literal, so a deployment can change a ceiling without touching the code that
 * enforces it, and an unknown limit cannot be silently defaulted to zero.
 */
enum Limit: string
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
     * The configured value for this limit.
     */
    public function value(): int
    {
        return (int) config('sender.limits.'.$this->value, 0);
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

<?php

declare(strict_types=1);

namespace App\Domain\Extraction\Url;

use RuntimeException;
use Throwable;

/**
 * A URL that failed to fetch, with a reason safe to show a customer.
 *
 * The exception carries a {@see UrlFailureReason} rather than a message derived
 * from the transport. libcurl and the HTTP client will happily describe the
 * resolved address, the proxy in use, or the port that refused, and none of that
 * belongs in front of a user who submitted the URL — it tells them more about
 * this network than about their own request.
 *
 * The raw diagnostic is retained separately for administrators, and goes
 * through the redaction path before being written anywhere durable.
 */
final class UrlFetchException extends RuntimeException
{
    public function __construct(
        public readonly UrlFailureReason $reason,
        private readonly ?string $detail = null,
        ?Throwable $previous = null,
    ) {
        // The message is the user-facing label, never the transport's.
        parent::__construct($reason->label(), 0, $previous);
    }

    public static function of(
        UrlFailureReason $reason,
        ?string $detail = null,
        ?Throwable $previous = null,
    ): self {
        return new self($reason, $detail, $previous);
    }

    /**
     * The raw diagnostic, for logging only. Never returned to a customer.
     */
    public function technicalDetail(): ?string
    {
        return $this->detail ?? $this->getPrevious()?->getMessage();
    }
}

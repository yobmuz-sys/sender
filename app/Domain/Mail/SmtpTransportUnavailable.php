<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use RuntimeException;

/**
 * A transport could not be built from a stored configuration.
 *
 * Raised before any socket is opened, so it always means the stored parameters
 * are wrong rather than the network. A message attempted against an incomplete
 * transport would fail later, in a place that reports a connection problem when
 * the real cause is a missing password.
 */
final class SmtpTransportUnavailable extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}

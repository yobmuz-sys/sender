<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use RuntimeException;

/**
 * An SMTP endpoint was refused before any connection was attempted.
 *
 * Separate from a transport failure because nothing was attempted: the host was
 * never resolved into a socket. Reporting these the same way would make an
 * operator chase a network problem that does not exist.
 */
final class SmtpEndpointRefused extends RuntimeException
{
    public function __construct(
        public readonly SmtpFailureReason $reason,
        public readonly string $host,
    ) {
        parent::__construct($reason->label());
    }

    public static function of(SmtpFailureReason $reason, string $host = ''): self
    {
        return new self($reason, $host);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * Why an SMTP operation failed, in terms a person can act on.
 *
 * The transport exception text quotes a server response, a socket address and
 * sometimes the rejected recipient. None of that is shown to a customer-facing
 * page, and the `last_failure_category` column records this enum instead.
 *
 * @see SmtpTransportFailure stores the enum; the raw text goes to the
 *      administrator's own logs after redaction.
 */
enum SmtpFailureReason: string
{
    case InvalidHost = 'invalid_host';
    case BlockedDestination = 'blocked_destination';
    case DnsFailure = 'dns_failure';
    case ConnectionFailed = 'connection_failed';
    case TlsFailed = 'tls_failed';
    case AuthenticationFailed = 'authentication_failed';
    case Rejected = 'rejected';
    case NotConfigured = 'not_configured';

    public function label(): string
    {
        return match ($this) {
            self::InvalidHost => 'The mail server address is not usable.',
            self::BlockedDestination => 'That mail server is not reachable from a public network.',
            self::DnsFailure => 'That mail server host name could not be resolved.',
            self::ConnectionFailed => 'The connection to the mail server failed.',
            self::TlsFailed => 'The secure connection to the mail server could not be established.',
            self::AuthenticationFailed => 'The mail server rejected these credentials.',
            self::Rejected => 'The mail server rejected the request.',
            self::NotConfigured => 'No usable transport is configured.',
        };
    }

    /**
     * Whether retrying the same configuration could plausibly succeed.
     *
     * Authentication is deliberately excluded. A wrong password stays wrong, and
     * retrying it against a provider is how a tenant gets rate-limited for no
     * reason.
     */
    public function isWorthRetrying(): bool
    {
        return match ($this) {
            self::ConnectionFailed, self::TlsFailed => true,
            default => false,
        };
    }

    /**
     * Whether the transport should stop being used until a person intervenes.
     *
     * A transport that rejected the credentials has not become safe to keep
     * trying, and continuing would be the platform rotating against a provider's
     * wishes.
     */
    public function shouldPauseAccount(): bool
    {
        return $this === self::AuthenticationFailed;
    }
}

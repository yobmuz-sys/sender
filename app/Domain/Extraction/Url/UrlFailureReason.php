<?php

declare(strict_types=1);

namespace App\Domain\Extraction\Url;

/**
 * Why a URL fetch did not produce content.
 *
 * A customer sees one of these and nothing else. A libcurl error number or a
 * Guzzle exception message can carry the resolved IP, the proxy in use, or
 * fragments of a URL the customer submitted but does not recognise — none of
 * which helps them and all of which leak the shape of this network.
 *
 * The categories are deliberately coarse. They answer "what kind of thing went
 * wrong", which is what a user can act on and what an operator can triage.
 * The detailed diagnostic is kept separately for administrators, through the
 * redaction path that already exists for run records.
 */
enum UrlFailureReason: string
{
    case InvalidUrl = 'invalid_url';
    case UnsupportedScheme = 'unsupported_scheme';
    case CredentialsInUrl = 'credentials_in_url';
    case UnsupportedPort = 'unsupported_port';
    case DnsFailure = 'dns_failure';
    case BlockedDestination = 'blocked_destination';
    case ConnectionTimeout = 'connection_timeout';
    case RequestTimeout = 'request_timeout';
    case TlsFailure = 'tls_failure';
    case RedirectLimit = 'redirect_limit';
    case ResponseTooLarge = 'response_too_large';
    case UnsupportedContentType = 'unsupported_content_type';
    case HttpError = 'http_error';
    case SubsystemDisabled = 'subsystem_disabled';

    public function label(): string
    {
        return match ($this) {
            self::InvalidUrl => 'That does not look like a web address.',
            self::UnsupportedScheme => 'Only http and https addresses can be fetched.',
            self::CredentialsInUrl => 'Addresses containing a username or password cannot be fetched.',
            self::UnsupportedPort => 'Only ports 80 and 443 can be fetched.',
            self::DnsFailure => 'That host name could not be resolved.',
            self::BlockedDestination => 'That address is not reachable from a public network.',
            self::ConnectionTimeout => 'The server did not accept a connection in time.',
            self::RequestTimeout => 'The server took too long to respond.',
            self::TlsFailure => 'The secure connection to that host could not be established.',
            self::RedirectLimit => 'That address redirected too many times.',
            self::ResponseTooLarge => 'The response was too large to process.',
            self::UnsupportedContentType => 'That address did not return text content.',
            self::HttpError => 'The server returned an error.',
            self::SubsystemDisabled => 'URL fetching is currently switched off.',
        };
    }

    /**
     * Whether retrying the same URL could plausibly succeed.
     *
     * Distinguishes the two kinds of failure a worker must treat differently: a
     * bad URL will fail identically forever, while a timeout or a 503 might not.
     * Used only to decide whether a retry is worth an attempt — the queue still
     * enforces the attempt limit.
     */
    public function isWorthRetrying(): bool
    {
        return match ($this) {
            self::ConnectionTimeout,
            self::RequestTimeout,
            self::TlsFailure,
            self::HttpError => true,
            default => false,
        };
    }
}

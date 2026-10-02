<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use App\Domain\Extraction\Url\DnsResolver;
use App\Domain\Extraction\Url\IpPolicy;
use App\Domain\Extraction\Url\UrlFailureReason;
use App\Domain\Extraction\Url\UrlFetchException;
use App\Domain\Extraction\Url\ValidatedUrl;

/**
 * Whether an SMTP endpoint is one this platform will connect to.
 *
 * The same hazard as URL fetching, in a different protocol. A tenant-supplied
 * SMTP host is an outbound network target, and the value of pointing a shared
 * host at `10.0.0.5:25` is that the host can reach it and the tenant cannot.
 * Ports are not the restriction here that they are for HTTP: mail servers live
 * on 25, 465, 587 and whatever a cPanel box uses, so refusing non-web ports
 * would refuse most of the legitimate cases. The address check is what carries
 * the security.
 *
 * Address reachability is deliberately not reimplemented. {@see DnsResolver} and
 * {@see IpPolicy} already answer exactly this
 * question, and a second implementation is a second thing to get wrong — and to
 * weaken later while fixing an unrelated bug.
 *
 * Pinning differs from the URL path and is called out honestly: the SMTP
 * transport resolves once, inside a single connection, and there is no
 * separate fetch-then-request sequence to rebind between. The check and the
 * connection are therefore adjacent rather than pinned, which is weaker than
 * the URL guarantee and is the best Symfony's transport offers without
 * reimplementing it.
 */
final class SmtpEndpointPolicy
{
    public function __construct(private readonly DnsResolver $resolver) {}

    public static function make(): self
    {
        return new self(new DnsResolver);
    }

    /**
     * Validate that a configured SMTP host resolves to a connectable public
     * destination.
     *
     * @throws SmtpEndpointRefused
     */
    public function assertConnectable(string $host): void
    {
        $host = strtolower(trim($host, '. '));

        if ($host === '') {
            throw SmtpEndpointRefused::of(SmtpFailureReason::InvalidHost);
        }

        $isLiteral = filter_var($host, FILTER_VALIDATE_IP) !== false;

        // Reused, not reimplemented: the validator is expressed in URL terms, so
        // a synthetic definition carries the host through the identical policy.
        $url = new ValidatedUrl(
            scheme: 'smtp',
            host: $host,
            port: 0,
            path: '',
            hostIsIpLiteral: $isLiteral,
        );

        try {
            $this->resolver->resolvePublicAddress($url);
        } catch (UrlFetchException $exception) {
            throw SmtpEndpointRefused::of(
                match ($exception->reason) {
                    UrlFailureReason::BlockedDestination => SmtpFailureReason::BlockedDestination,
                    UrlFailureReason::DnsFailure => SmtpFailureReason::DnsFailure,
                    UrlFailureReason::InvalidUrl => SmtpFailureReason::InvalidHost,
                    default => SmtpFailureReason::BlockedDestination,
                },
                $host,
            );
        }
    }
}

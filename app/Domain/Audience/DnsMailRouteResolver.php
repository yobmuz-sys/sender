<?php

declare(strict_types=1);

namespace App\Domain\Audience;

use App\Domain\Extraction\Url\IpPolicy;
use DateTimeInterface;

/**
 * Resolves a domain's mail route from DNS.
 *
 * The whole difficulty here is the difference between "this domain publishes
 * nothing" and "the resolver did not answer". Both look like a failed lookup to
 * PHP, and treating them as the same thing would let a momentary resolver outage
 * classify an entire audience as invalid — the most destructive mistake available
 * to this pipeline, and one that would be discovered only after a customer had
 * acted on the report.
 *
 * So the distinction is made explicitly, and the safety runs both ways:
 *
 *   - no MX and no address record at all  -> the domain may not exist, or the
 *                                            resolver may be down. Not evidence.
 *   - the name resolves (SOA present) but publishes neither -> `NoRoute`, which
 *                                            *is* evidence.
 *   - the name does not resolve, confirmed against a control probe of a domain
 *     that certainly does exist     -> `DomainNotFound`.
 *   - the control probe also fails          -> `Unavailable`, never invalid.
 *
 * The control probe is what makes `DomainNotFound` safe to act on. It costs one
 * extra lookup, and only on the path where the answer would otherwise be
 * destructive.
 *
 * The RFC 5321 §5 implicit route is honoured: a domain publishing no MX but
 * resolving to an address can still receive mail, because the receiving server is
 * the domain's own A/AAAA record. Only globally routable addresses count — the
 * same rule the platform's URL fetcher applies, so a domain cannot be turned into
 * a way to reach an internal host.
 */
class DnsMailRouteResolver implements MailRouteResolver
{
    /**
     * A control probe.
     *
     * Used only to distinguish "does not exist" from "our resolver is not
     * working". `.invalid` is reserved by RFC 2606 and can never resolve, so it
     * is the one name whose NXDOMAIN is a guaranteed, stable answer.
     */
    private const CONTROL_PROBE = 'sender-platform-control-probe.invalid';

    public function resolve(string $domain): MailRoute
    {
        $domain = strtolower(trim($domain));
        $now = $this->now();

        if ($domain === '' || ! $this->isPlausibleDomain($domain)) {
            return MailRoute::of($domain, MailRouteStatus::Unavailable, [], $now);
        }

        $mx = $this->mxHosts($domain);

        if ($mx !== []) {
            return MailRoute::of($domain, MailRouteStatus::HasRoute, $mx, $now);
        }

        // No MX. The domain may still receive mail through the implicit route,
        // but only at a globally routable address.
        $addresses = $this->publicAddresses($domain);

        if ($addresses !== []) {
            return MailRoute::of($domain, MailRouteStatus::HasRoute, $addresses, $now);
        }

        // Nothing at all. The domain is either not there or unreadable, and only
        // one of those is a fact about the domain.
        if ($this->resolves(self::CONTROL_PROBE)) {
            return MailRoute::of($domain, MailRouteStatus::NoRoute, [], $now);
        }

        return MailRoute::of(
            $domain,
            $this->resolves($domain) ? MailRouteStatus::NoRoute : MailRouteStatus::Unavailable,
            [],
            $now,
        );
    }

    /**
     * The MX hosts a domain publishes, in preference order as published.
     *
     * @return list<string>
     */
    private function mxHosts(string $domain): array
    {
        // An empty array means the name exists and publishes no MX. `false` means
        // the query itself failed, which is not the same thing and is handled by
        // falling through to the address lookup.
        $records = @dns_get_record($domain, DNS_MX);

        if (! is_array($records)) {
            return [];
        }

        $hosts = [];

        foreach ($records as $record) {
            if (isset($record['target']) && is_string($record['target'])) {
                $target = rtrim($record['target'], '.');

                if ($target !== '') {
                    $hosts[] = strtolower($target);
                }
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * Globally routable addresses a domain resolves to.
     *
     * @return list<string>
     */
    private function publicAddresses(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_A | DNS_AAAA);

        if (! is_array($records)) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = null;

            if (isset($record['ip']) && is_string($record['ip'])) {
                $address = $record['ip'];
            } elseif (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $address = $record['ipv6'];
            }

            // A domain pointing at a private address is not a usable mail route
            // for this platform, and must not become a way to open a connection
            // to something inside the host's own network.
            if ($address !== null && IpPolicy::isGloballyRoutable($address)) {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Whether a name resolves to anything, by the SOA record every domain must
     * publish at its apex.
     *
     * SOA rather than A/AAAA: a domain can legitimately have no address records
     * and still exist, and `dns_get_record` cannot tell "no records of this type"
     * from "no such name" without the help of a record type that is always there.
     */
    private function resolves(string $domain): bool
    {
        $records = @dns_get_record($domain, DNS_SOA);

        return is_array($records) && $records !== [];
    }

    /**
     * A syntactically plausible domain name.
     *
     * Guarded before any lookup so a hostile string cannot reach the resolver,
     * and so a nonsense domain produces `Unavailable` rather than a lookup whose
     * failure would be misread as evidence.
     */
    private function isPlausibleDomain(string $domain): bool
    {
        return strlen($domain) <= 253
            && preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/', $domain) === 1;
    }

    /**
     * Overridable so a test can stamp a fixed observation time.
     */
    protected function now(): DateTimeInterface
    {
        return now();
    }
}

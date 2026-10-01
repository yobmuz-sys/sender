<?php

declare(strict_types=1);

namespace App\Domain\Extraction\Url;

/**
 * Resolves a host name to the addresses this platform is willing to connect to.
 *
 * Two decisions live here, and the second is the one that matters.
 *
 * **Every** resolved address must be globally routable. Accepting a host that
 * resolves to one public and one private address would leave the choice to
 * whoever answers next — and the answer can differ between this lookup and the
 * connection that follows it. Rejecting the whole host removes the decision.
 *
 * **The chosen address is returned to the caller, which pins it.** Resolving,
 * checking, and then performing an ordinary hostname request is the standard
 * mistake, and it defeats everything above: libcurl resolves the name again when
 * it opens the socket, so an attacker who controls the DNS can answer with a
 * public address for this lookup and a loopback address for that one. The
 * caller must therefore pass the address here straight into CURLOPT_RESOLVE, so
 * the connection uses the address that was validated and no other.
 *
 * Not final, so a test can substitute a fixed answer and exercise the pinning
 * above without performing a real lookup. Nothing else may extend it: the
 * production path always uses this class.
 */
class DnsResolver
{
    /**
     * Resolve and validate, returning the one address the request may use.
     *
     * @throws UrlFetchException
     */
    public function resolvePublicAddress(ValidatedUrl $url): string
    {
        if ($url->hostIsIpLiteral) {
            // Already an address. No lookup happens, so there is nothing to
            // rebind — but it still has to be one we are willing to talk to.
            if (! IpPolicy::isGloballyRoutable($url->host)) {
                throw UrlFetchException::of(UrlFailureReason::BlockedDestination);
            }

            return $url->host;
        }

        $addresses = $this->addressesFor($url->host);

        if ($addresses === []) {
            throw UrlFetchException::of(UrlFailureReason::DnsFailure);
        }

        foreach ($addresses as $address) {
            if (! IpPolicy::isGloballyRoutable($address)) {
                // One bad address refuses the whole host. See the class note.
                throw UrlFetchException::of(UrlFailureReason::BlockedDestination);
            }
        }

        return $addresses[0];
    }

    /**
     * Every A and AAAA record for a host.
     *
     * `dns_get_record` rather than `gethostbynamel`, because the latter is
     * IPv4-only and an IPv6-only host would appear not to resolve at all.
     *
     * @return list<string>
     */
    private function addressesFor(string $host): array
    {
        $addresses = [];

        // Suppressed because a resolver failure emits a warning before we get
        // to turn it into a categorised result, and the warning is noise.
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip'])) {
                    $addresses[] = (string) $record['ip'];
                }

                if (isset($record['ipv6'])) {
                    $addresses[] = (string) $record['ipv6'];
                }
            }
        }

        // A CNAME-only host resolves through the name it points at, so fall
        // back to the platform resolver rather than treating it as unresolvable.
        if ($addresses === []) {
            $fallback = @gethostbynamel($host);

            if (is_array($fallback)) {
                $addresses = $fallback;
            }
        }

        return array_values(array_unique($addresses));
    }
}

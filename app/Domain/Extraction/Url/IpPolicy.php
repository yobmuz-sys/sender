<?php

declare(strict_types=1);

namespace App\Domain\Extraction\Url;

/**
 * Whether an IP address is one this platform is willing to connect to.
 *
 * This is the security boundary for every outbound request. A URL the platform
 * fetches is a URL a third party supplied, so the danger is not the path or the
 * query string — it is that the host name resolves to something inside the
 * network the application itself runs in. Fetching it would let a user read a
 * response the platform can see but they cannot, and would let them reach
 * services — metadata endpoints, admin panels, databases bound to localhost —
 * that assume only trusted callers.
 *
 * The rule is "must be globally routable", rather than a list of the private
 * ranges to avoid. A blacklist is wrong on its own terms: it has to be
 * complete, and every range someone forgets is a hole. `FILTER_FLAG_GLOBAL_RANGE`
 * asks the positive question instead, so an address space this runtime has not
 * heard of is refused rather than permitted.
 *
 * The three flags together also cover the cases people forget individually:
 *
 *   - loopback and link-local (169.254.0.0/16) include the cloud metadata
 *     endpoint, which is the single most valuable SSRF target there is
 *   - `0.0.0.0/8` and `::/128` resolve to "this host" on many stacks
 *   - the reserved blocks are not private, but are not public either
 */
final class IpPolicy
{
    /**
     * Whether a resolved address may be connected to.
     *
     * @param  string  $ip  A dotted-quad or colon-form address, as returned by
     *                      the resolver.
     */
    public static function isGloballyRoutable(string $ip): bool
    {
        $validated = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_GLOBAL_RANGE | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );

        if ($validated === false) {
            // Not a valid address at all, or one that fails the public-range
            // test. Either way the answer is no.
            return false;
        }

        // Belt and braces for the shapes the flags above are documented to
        // exclude but which are worth asserting explicitly rather than trusting:
        // unspecified, multicast, and the IPv4-compatible IPv6 range that
        // wraps an IPv4 address and could otherwise smuggle one past a check.
        return match (true) {
            filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                && str_starts_with($ip, '0.') => false,
            self::isMulticast($ip) => false,
            self::isIpv4Mapped($ip) => false,
            default => true,
        };
    }

    private static function isMulticast(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return str_starts_with($ip, '224.') || str_starts_with($ip, '225.')
                || str_starts_with($ip, '226.') || str_starts_with($ip, '227.')
                || str_starts_with($ip, '228.') || str_starts_with($ip, '229.')
                || str_starts_with($ip, '230.') || str_starts_with($ip, '231.');
        }

        return str_starts_with(strtolower($ip), 'ff');
    }

    /**
     * IPv4-mapped and IPv4-compatible IPv6 addresses.
     *
     * `::ffff:127.0.0.1` is an IPv6 spelling of an IPv4 loopback address. If the
     * runtime's filter accepted it as global, a single carefully chosen literal
     * would bypass every other check here.
     */
    private static function isIpv4Mapped(string $ip): bool
    {
        $normalised = strtolower($ip);

        return str_starts_with($normalised, '::ffff:') || str_starts_with($normalised, '::');
    }
}

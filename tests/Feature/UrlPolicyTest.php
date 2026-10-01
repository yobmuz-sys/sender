<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Extraction\Url\DnsResolver;
use App\Domain\Extraction\Url\IpPolicy;
use App\Domain\Extraction\Url\UrlFailureReason;
use App\Domain\Extraction\Url\UrlFetchException;
use App\Domain\Extraction\Url\UrlValidator;
use App\Domain\Extraction\Url\ValidatedUrl;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The network policy, tested without touching a network.
 *
 * These are the checks that decide whether the platform will connect to
 * something. A regression in any of them is not a bug report — it is an SSRF
 * hole — so they are tested as exhaustively as the cases can be enumerated,
 * including the shapes that exist specifically to defeat a naive filter.
 */
class UrlPolicyTest extends TestCase
{
    #[Test]
    public function a_plain_http_url_is_accepted(): void
    {
        $url = $this->validate('http://example.com/contact');

        $this->assertSame('http', $url->scheme);
        $this->assertSame('example.com', $url->host);
        $this->assertSame(80, $url->port);
    }

    #[Test]
    public function a_plain_https_url_is_accepted(): void
    {
        $url = $this->validate('https://example.com/contact');

        $this->assertSame('https', $url->scheme);
        $this->assertSame(443, $url->port);
    }

    #[Test]
    public function a_url_with_no_scheme_is_rejected(): void
    {
        // `example.com` alone would otherwise be treated as a path.
        $this->assertReason('invalid_url', 'example.com/contact');
    }

    #[Test]
    public function every_scheme_other_than_http_and_https_is_rejected(): void
    {
        foreach ([
            'file:///etc/passwd',
            'ftp://example.com/pub',
            'gopher://example.com/',
            'data:text/plain,hello@example.com',
            'javascript:alert(1)',
        ] as $url) {
            $this->assertReason(
                UrlFailureReason::UnsupportedScheme->value,
                $url,
                $url.' must not be fetchable',
            );
        }
    }

    #[Test]
    public function a_url_containing_credentials_is_rejected(): void
    {
        // Nothing this version fetches needs credentials, and accepting them
        // would let a user point the platform at a login it will perform for
        // them, or send those credentials wherever a redirect says.
        foreach ([
            'http://user:pass@example.com/',
            'http://user@example.com/',
            'http://:pass@example.com/',
        ] as $url) {
            $this->assertReason(UrlFailureReason::CredentialsInUrl->value, $url);
        }
    }

    #[Test]
    public function a_port_other_than_eighty_or_four_hundred_forty_three_is_rejected(): void
    {
        // The restriction is what stops this being a port scanner for whatever
        // else a reachable host happens to be running.
        foreach ([
            'http://example.com:22/',
            'http://example.com:8080/',
            'http://example.com:6379/',
            'http://example.com:3306/',
            'https://example.com:8443/',
        ] as $url) {
            $this->assertReason(UrlFailureReason::UnsupportedPort->value, $url);
        }
    }

    #[Test]
    public function an_explicitly_allowed_port_is_accepted(): void
    {
        $this->assertSame(443, $this->validate('https://example.com:443/')->port);
        $this->assertSame(80, $this->validate('http://example.com:80/')->port);
    }

    #[Test]
    public function an_excessive_url_length_is_rejected(): void
    {
        $long = 'https://example.com/'.str_repeat('a', (int) config('sender.url_fetch.max_url_length'));

        $this->assertReason(UrlFailureReason::InvalidUrl->value, $long);
    }

    #[Test]
    public function a_url_of_exactly_the_maximum_length_is_accepted(): void
    {
        $limit = (int) config('sender.url_fetch.max_url_length');
        $url = 'https://example.com/'.str_repeat('a', $limit - 20);

        $this->assertLessThanOrEqual($limit, strlen($url));
        $this->assertSame('example.com', $this->validate($url)->host);
    }

    #[Test]
    public function loopback_ipv4_is_refused(): void
    {
        $this->assertRefused('127.0.0.1');
        $this->assertRefused('127.1.2.3');
    }

    #[Test]
    public function rfc1918_private_ipv4_is_refused(): void
    {
        foreach (['10.0.0.1', '172.16.0.1', '172.31.255.255', '192.168.1.1'] as $ip) {
            $this->assertRefused($ip);
        }
    }

    #[Test]
    public function link_local_is_refused(): void
    {
        // 169.254.0.0/16 is where cloud metadata endpoints live. Fetching one
        // returns credentials to whoever asked, which is the single most
        // valuable thing an SSRF can be used for.
        $this->assertRefused('169.254.169.254');
        $this->assertRefused('169.254.0.1');
    }

    #[Test]
    public function reserved_ipv4_is_refused(): void
    {
        foreach ([
            '0.0.0.0',          // "this host"
            '100.64.0.1',       // carrier-grade NAT
            '192.0.2.1',        // TEST-NET-1
            '198.18.0.1',       // benchmarking
            '224.0.0.1',        // multicast
            '255.255.255.255',  // broadcast
        ] as $ip) {
            $this->assertRefused($ip);
        }
    }

    #[Test]
    public function loopback_and_private_ipv6_are_refused(): void
    {
        $this->assertRefused('::1');
        $this->assertRefused('fc00::1');
        $this->assertRefused('fd12:3456::1');
        $this->assertRefused('fe80::1');   // link-local
    }

    #[Test]
    public function an_ipv6_literal_that_spoofs_an_ipv4_loopback_is_refused(): void
    {
        // `::ffff:127.0.0.1` is an IPv6 spelling of IPv4 loopback. A filter
        // that only understands the address family it was written for would
        // accept it and every other check would be bypassed.
        $this->assertRefused('::ffff:127.0.0.1');
        $this->assertRefused('::ffff:10.0.0.1');
    }

    #[Test]
    public function public_addresses_are_accepted(): void
    {
        foreach ([
            '8.8.8.8',
            '1.1.1.1',
            '93.184.216.34',
            '2606:2800:220:1:248:1893:25c8:1946',
        ] as $ip) {
            $this->assertTrue(
                IpPolicy::isGloballyRoutable($ip),
                $ip.' is public and must be allowed',
            );
        }
    }

    #[Test]
    public function something_that_is_not_an_address_is_refused(): void
    {
        // Failing closed matters more here than anywhere else in the policy: an
        // unparseable address must never be treated as permitted.
        foreach (['', 'not-an-ip', '999.999.999.999', '12345', '::gg'] as $ip) {
            $this->assertFalse(
                IpPolicy::isGloballyRoutable($ip),
                $ip.' must not be treated as routable',
            );
        }
    }

    #[Test]
    public function a_loopback_address_written_into_the_url_is_refused(): void
    {
        // Written directly into the URL, which skips DNS entirely and so skips
        // every lookup-based defence. It must be refused by the resolver, which
        // is the only place that sees an address without resolving it.
        $this->assertResolvedRefused('http://127.0.0.1/');
        $this->assertResolvedRefused('http://[::1]/');
        $this->assertResolvedRefused('http://169.254.169.254/latest/meta-data/');
        $this->assertResolvedRefused('http://10.0.0.5/admin');
    }

    private function assertResolvedRefused(string $url): void
    {
        $validated = UrlValidator::fromConfiguration()->validate($url);

        try {
            (new DnsResolver)->resolvePublicAddress($validated);
        } catch (UrlFetchException $exception) {
            $this->assertSame(
                UrlFailureReason::BlockedDestination->value,
                $exception->reason->value,
                $url.' must not resolve to a connectable destination',
            );

            return;
        }

        $this->fail($url.' should not have resolved to a connectable destination');
    }

    private function validate(string $url): ValidatedUrl
    {
        return UrlValidator::fromConfiguration()->validate($url);
    }

    private function assertReason(string $expected, string $url, string $message = ''): void
    {
        try {
            $this->validate($url);
        } catch (UrlFetchException $exception) {
            $this->assertSame(
                $expected,
                $exception->reason->value,
                $message !== '' ? $message : $url.' should have been refused as '.$expected,
            );

            return;
        }

        $this->fail($url.' should have been refused as '.$expected);
    }

    private function assertRefused(string $ip): void
    {
        $this->assertFalse(
            IpPolicy::isGloballyRoutable($ip),
            $ip.' must not be considered globally routable',
        );
    }
}

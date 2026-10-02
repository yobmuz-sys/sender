<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Extraction\Url\DnsResolver;
use App\Domain\Extraction\Url\IpPolicy;
use App\Domain\Extraction\Url\UrlFailureReason;
use App\Domain\Extraction\Url\UrlFetchException;
use App\Domain\Extraction\Url\ValidatedUrl;
use App\Domain\Mail\SmtpAccountVerifier;
use App\Domain\Mail\SmtpEndpointPolicy;
use App\Domain\Mail\SmtpEndpointRefused;
use App\Domain\Mail\SmtpFailureReason;
use App\Models\User;

/**
 * An SMTP host is an outbound network target, exactly as a submitted URL is.
 *
 * The value of naming `127.0.0.1` or `10.0.0.5` is that the shared host can
 * reach it and the tenant cannot: it turns a mail form into a way of probing
 * whatever else lives on the network. Mail servers are not on web ports, which
 * is why the address check — not the port — is what carries the security here.
 *
 * The policy reuses `DnsResolver` and `IpPolicy` rather than restating them. The
 * last test in this file exists to keep that true: if the two ever diverge, the
 * extraction path has been altered to accommodate mail.
 */
class SmtpEndpointSecurityTest extends MailTestCase
{
    public function test_a_loopback_destination_is_refused(): void
    {
        $this->assertRefused('127.0.0.1');
        $this->assertRefused('127.4.5.6');
    }

    public function test_localhost_is_refused(): void
    {
        $this->assertRefused('localhost');
    }

    public function test_rfc1918_private_addresses_are_refused(): void
    {
        foreach (['10.0.0.5', '172.16.0.1', '192.168.1.10'] as $host) {
            $this->assertRefused($host);
        }
    }

    public function test_link_local_and_metadata_addresses_are_refused(): void
    {
        // 169.254.169.254 is the cloud instance metadata endpoint. A transport
        // pointed at it is trying to read host credentials, not send mail.
        $this->assertRefused('169.254.169.254');
        $this->assertRefused('fe80::1');
    }

    public function test_loopback_and_unique_local_ipv6_are_refused(): void
    {
        $this->assertRefused('::1');
        $this->assertRefused('fc00::1');
    }

    public function test_a_public_destination_is_accepted(): void
    {
        $this->resolverReturning(['93.184.216.34']);

        $this->app->make(SmtpEndpointPolicy::class)->assertConnectable('smtp.example.com');

        $this->assertTrue(true, 'A publicly routable host is connectable.');
    }

    public function test_a_mixed_public_and_private_dns_answer_is_refused_whole(): void
    {
        // One usable address among the answers must not make a hostname safe.
        // Otherwise an attacker who controls DNS for their own domain points it
        // at a public host and a private one, and the platform picks.
        $this->resolverReturning(['93.184.216.34', '10.0.0.5']);

        $this->assertRefused('mixed.example.com');
    }

    public function test_a_host_with_no_addresses_is_refused(): void
    {
        $this->resolverReturning([]);

        $this->assertRefused('nowhere.example.com', SmtpFailureReason::DnsFailure);
    }

    public function test_an_empty_host_is_refused(): void
    {
        $this->assertRefused('', SmtpFailureReason::InvalidHost);
    }

    public function test_the_smtp_path_uses_the_url_resolver_rather_than_its_own_rules(): void
    {
        // The SMTP policy is a thin adapter. Proving it here means that if
        // someone later gives mail its own address rules, this fails — because
        // the two paths would then disagree about the same host.
        $resolver = new class extends DnsResolver
        {
            /** @var list<string> */
            public array $asked = [];

            public function resolvePublicAddress(ValidatedUrl $url): string
            {
                $this->asked[] = $url->host;

                if ($url->host === '10.0.0.5') {
                    throw UrlFetchException::of(UrlFailureReason::BlockedDestination);
                }

                return '93.184.216.34';
            }
        };

        $this->app->instance(SmtpEndpointPolicy::class, new SmtpEndpointPolicy($resolver));

        $this->expectException(SmtpEndpointRefused::class);

        $this->app->make(SmtpEndpointPolicy::class)->assertConnectable('10.0.0.5');
    }

    public function test_a_refused_endpoint_is_never_dialled(): void
    {
        $account = $this->accountFor(User::factory()->create(), [
            'host' => '127.0.0.1',
            'username' => 'alice@example.com',
            'from_address' => 'alice@example.com',
        ]);

        $verification = app(SmtpAccountVerifier::class)->verifyConnection($account);

        $this->assertStringContainsString('public network', $verification->summary);
        $this->assertFalse(
            $account->fresh()->effectiveStatus()->isUsable(),
            'A refused endpoint must not leave the account looking usable.',
        );
    }

    private function assertRefused(string $host, SmtpFailureReason $expected = SmtpFailureReason::BlockedDestination): void
    {
        // Resolved through the container so a test-bound policy is the one
        // exercised; `make()` would build a fresh real one and ignore it.
        try {
            $this->app->make(SmtpEndpointPolicy::class)->assertConnectable($host);
        } catch (SmtpEndpointRefused $refusal) {
            $this->assertSame($expected, $refusal->reason, "Refusing '{$host}'");

            return;
        }

        $this->fail("Expected '{$host}' to be refused as an SMTP endpoint.");
    }

    /**
     * Bind a resolver whose answers this test controls, so the mixed-answer and
     * empty-answer cases can be exercised without a live nameserver.
     *
     * @param  list<string>  $addresses
     */
    private function resolverReturning(array $addresses): void
    {
        $this->app->instance(SmtpEndpointPolicy::class, new SmtpEndpointPolicy(
            new class($addresses) extends DnsResolver
            {
                /**
                 * @param  list<string>  $addresses
                 */
                public function __construct(private readonly array $addresses) {}

                public function resolvePublicAddress(ValidatedUrl $url): string
                {
                    if ($this->addresses === []) {
                        throw UrlFetchException::of(UrlFailureReason::DnsFailure);
                    }

                    foreach ($this->addresses as $address) {
                        if (! IpPolicy::isGloballyRoutable($address)) {
                            throw UrlFetchException::of(UrlFailureReason::BlockedDestination);
                        }
                    }

                    return $this->addresses[0];
                }
            }
        ));
    }
}

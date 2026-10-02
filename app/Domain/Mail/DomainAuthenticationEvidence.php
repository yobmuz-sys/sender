<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use App\Domain\Extraction\Url\DnsResolver;

/**
 * Reads the authentication records this platform can observe for a domain.
 *
 * Everything here is evidence about *DNS*, never about a message. That
 * distinction is the whole reason these methods exist, and the reason they return
 * records rather than verdicts on outgoing mail:
 *
 *   - A published SPF record says the domain nominates senders. It does not say
 *     that the SMTP provider the tenant selected is among them, and this
 *     platform has no way to check the final message's SPF result.
 *   - A DKIM record at a *known selector* is verifiable. Without the selector
 *     the provider signed with, any `_domainkey` record found is a guess, and a
 *     guess reported as a pass is exactly the kind of false reassurance this
 *     report exists to avoid.
 *   - DMARC is a published policy about handling failures. Its presence is
 *     checkable; whether alignment is achieved for a particular message is not.
 *
 * So a missing record is a finding, a present record is a finding, and anything
 * requiring knowledge of the final message is `Unknown`.
 *
 * Resolution is bounded and non-blocking: a nameserver that does not answer must
 * not turn a page render into a multi-second wait. Every method reports what it
 * saw, including "nothing".
 *
 * Not final, so a test can supply fixed records and assert on the findings
 * without depending on what any real domain currently publishes. Nothing else may
 * extend it; the production path always uses this class. Same arrangement as
 * {@see DnsResolver}.
 */
class DomainAuthenticationEvidence
{
    public function __construct() {}

    /**
     * The SPF records published for a domain.
     *
     * @return list<string>
     */
    public function spfRecords(string $domain): array
    {
        return $this->txtRecords($domain);
    }

    /**
     * Whether a usable SPF record is published.
     *
     * `v=spf1` is required. A TXT record containing the word "spf" somewhere is
     * not an SPF record, and accepting one would report a domain as
     * authenticated when it has published nothing of the kind.
     */
    public function hasSpf(string $domain): bool
    {
        foreach ($this->spfRecords($domain) as $record) {
            if (stripos(ltrim($record), 'v=spf1') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The raw records at a DKIM selector.
     *
     * @return list<string>
     */
    public function dkimRecords(string $domain, string $selector): array
    {
        return $this->txtRecords($this->selector($domain, $selector));
    }

    /**
     * Whether a DKIM public key is published at the given selector.
     */
    public function hasDkimSelector(string $domain, string $selector): bool
    {
        if ($selector === '') {
            return false;
        }

        foreach ($this->dkimRecords($domain, $selector) as $record) {
            if (preg_match('/(^|\s)p=([^;\s]*)/i', $record, $matches) !== 1) {
                continue;
            }

            // An empty p= is a published *revocation*. Reporting that as an
            // enabled key would invert the meaning of the record.
            return $matches[2] !== '';
        }

        return false;
    }

    /**
     * The DMARC record published for a domain, or null.
     *
     * RFC 7489 requires the record at `_dmarc.<domain>`, with any organisation
     * domain handled through the `rua`/`ruf` fallbacks the provider publishes.
     */
    public function dmarcRecord(string $domain): ?string
    {
        $records = $this->txtRecords('_dmarc.'.$domain);

        foreach ($records as $record) {
            if (stripos($record, 'v=DMARC1') !== false) {
                return $record;
            }
        }

        return null;
    }

    public function hasDmarc(string $domain): bool
    {
        return $this->dmarcRecord($domain) !== null;
    }

    /**
     * The published DMARC policy, or null when absent or unparseable.
     *
     * `p=none` counts. Reporting a monitoring policy as a failure would
     * misrepresent what the domain owner has committed to, and `p=none` is a
     * legitimate starting position for a domain gathering evidence.
     */
    public function dmarcPolicy(string $domain): ?string
    {
        $record = $this->dmarcRecord($domain);

        if ($record === null) {
            return null;
        }

        if (preg_match('/(^|\s)p=([a-z]+)/i', $record, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[2]);
    }

    /**
     * Whether the DMARC record declares subdomain alignment requirements.
     *
     * Used only to describe what the domain has published. Whether an outgoing
     * message satisfies `adkim`/`aspf` is not observable from here.
     *
     * @return array{adkim: string|null, aspf: string|null}
     */
    public function dmarcAlignment(string $domain): array
    {
        $record = $this->dmarcRecord($domain);

        if ($record === null) {
            return ['adkim' => null, 'aspf' => null];
        }

        $read = static function (string $tag) use ($record): ?string {
            return preg_match('/(^|\s)'.$tag.'=([a-z]+)/i', $record, $m) === 1
                ? strtolower($m[2])
                : null;
        };

        return ['adkim' => $read('adkim'), 'aspf' => $read('aspf')];
    }

    /**
     * The reverse DNS name published for an address, or null.
     *
     * `gethostbyaddr()` returns the address unchanged when no PTR record exists,
     * so an unchanged result is reported as absent rather than as a name that
     * happens to look like an address.
     */
    public function reverseName(string $address): ?string
    {
        $name = @gethostbyaddr($address);

        if ($name === false || $name === '' || $name === $address) {
            return null;
        }

        return $name;
    }

    /**
     * Whether an address publishes a PTR that resolves back to itself.
     *
     * Forward-confirmed reverse DNS is the check providers require of a sender's
     * own infrastructure. Whether it holds for a *relay* tells us something about
     * the relay, not about the sender — see {@see DeliveryReadiness}, which is
     * why the result is an observation rather than a verdict.
     */
    public function forwardConfirmed(string $address): bool
    {
        $name = $this->reverseName($address);

        if ($name === null) {
            return false;
        }

        $forward = @gethostbyname($name);

        return $forward !== $name && $forward === $address;
    }

    /**
     * @return list<string>
     */
    private function txtRecords(string $name): array
    {
        if ($name === '' || ! preg_match('/^[a-z0-9._-]+$/i', $name)) {
            return [];
        }

        // Bounded, and a failure is reported as "no records found" rather than
        // thrown: an unreachable resolver is not evidence about the domain, and
        // it must not become a 500 on a dashboard.
        $records = @dns_get_record($name, DNS_TXT);

        if (! is_array($records)) {
            return [];
        }

        $out = [];

        foreach ($records as $record) {
            if (isset($record['txt']) && is_string($record['txt'])) {
                $out[] = $record['txt'];
            } elseif (isset($record['data']) && is_string($record['data'])) {
                // Windows and some builds split long TXT records into chunks.
                $out[] = $record['data'];
            }
        }

        return $out;
    }

    private function selector(string $domain, string $selector): string
    {
        return $selector.'._domainkey.'.$domain;
    }
}

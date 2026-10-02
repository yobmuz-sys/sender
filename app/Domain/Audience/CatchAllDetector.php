<?php

declare(strict_types=1);

namespace App\Domain\Audience;

/**
 * Determines whether a domain accepts mail for addresses that cannot exist.
 *
 * This is the most valuable single check in the pipeline, and it protects against
 * the error that matters most: calling an address active because the server
 * accepted it, when the server would have accepted anything.
 *
 * A domain configured to accept all mail — "catch-all", or what some providers
 * sell as a catch-all domain — returns `250` for every recipient, including
 * addresses nobody has ever registered. Against such a domain, SMTP recipient
 * validation returns "active" for the whole list, every time, and every one of
 * those results is worthless. Worse, it is worthless in the direction that looks
 * like success: the report says every address is good, the customer sends, and
 * the messages are silently discarded by the receiving server after being
 * accepted without ever bouncing.
 *
 * So the domain is probed once with an address that cannot exist, and if that is
 * accepted, every mailbox at the domain becomes `UNKNOWN`:
 *
 *     domain accepts a synthetic address
 *         -> acceptance proves nothing
 *         -> UNKNOWN for every address at that domain
 *
 * The synthetic address is built from 32 hex characters of cryptographic
 * randomness, which is what makes the probe meaningful. A guessable probe
 * address (`test@example.com`) is useless, because a mail server is entitled to
 * treat well-known local parts specially — delivering them to a catch-all or
 * discarding them by policy — and a domain that discards `test` while accepting
 * everything else would be reported as a normal domain.
 *
 * One probe per domain per cache window. Repeatedly asking a mail server about
 * addresses that cannot exist is itself the behaviour that gets a sending host
 * blocked, so the cache is not merely an optimisation here.
 */
final class CatchAllDetector
{
    public function __construct(
        private readonly RecipientProber $prober,
    ) {}

    /**
     * Probe a domain and report what it means for every mailbox at it.
     *
     * @param  string  $mailHost  Where to ask — an MX target, or the domain's own
     *                            address when it relies on the implicit route.
     * @param  string  $domain  The recipient's domain, which is where the
     *                          synthetic address must be constructed.
     */
    public function detect(string $mailHost, string $domain): CatchAllVerdict
    {
        $address = $this->syntheticAddressFor($domain);

        if ($address === null) {
            return CatchAllVerdict::Unknown;
        }

        $result = $this->prober->probe($mailHost, $address);
        $code = (int) $result->code;

        if (! $result->wasAnswered()) {
            // No reply is not evidence either way. A server we could not reach
            // has not told us that it accepts everything.
            return CatchAllVerdict::Unknown;
        }

        if ($code === 252) {
            // The server declined to verify. For a deliberately random address
            // that is not evidence of catch-all behaviour.
            return CatchAllVerdict::Unknown;
        }

        if ($code >= 500) {
            // A synthetic address that cannot exist was rejected, so the domain
            // discriminates between real and imaginary recipients.
            return CatchAllVerdict::No;
        }

        if ($code >= 200 && $code < 300) {
            return CatchAllVerdict::Yes;
        }

        // 4xx and anything unrecognised: inconclusive.
        return CatchAllVerdict::Unknown;
    }

    /**
     * The nonce address probed at a domain, or null if one cannot be built.
     *
     * Public so a test can assert the address is unpredictable and correctly
     * formed without having to reach a mail server to do it.
     */
    public function syntheticAddressFor(string $domain): ?string
    {
        $domain = strtolower(trim($domain));

        if ($domain === '' || strlen($domain) > 253 || preg_match('/^[a-z0-9.-]+$/i', $domain) !== 1) {
            return null;
        }

        return 'sender-check-'.bin2hex(random_bytes(16)).'@'.$domain;
    }
}

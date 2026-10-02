<?php

declare(strict_types=1);

namespace App\Domain\Audience;

use App\Domain\Extraction\Url\IpPolicy;

/**
 * A real SMTP recipient check: connect, ask, disconnect.
 *
 * The conversation is deliberately three commands long — EHLO, MAIL FROM, RCPT TO,
 * then RSET and QUIT — and it never reaches DATA. Nothing is transmitted. There is
 * no message body, no subject and no recipient-visible artefact, because a
 * validator that emails every address on a list to find out whether it exists is
 * doing to those recipients exactly what this platform exists to prevent.
 *
 * VRFY is not used at all. RFC 5321 §4.1.4 requires it to be disabled, and a
 * server offering it is offering precisely the enumeration signal the receiving
 * providers protect themselves from.
 *
 * **On shared hosting this will usually not work**, and that is a normal
 * outcome rather than a failure. Outbound port 25 is blocked by most cPanel hosts
 * by default, precisely because unsolicited outbound mail from a shared account
 * is what gets an entire host suspended. So:
 *
 *   - the connection is opt-in, through configuration
 *   - a refusal, a timeout or a route that cannot be dialled returns `UNKNOWN`
 *   - nothing about a refused connection is ever reported as a dead address
 *
 * A platform that quietly classified every address as invalid because its host
 * blocks port 25 would look like it worked while destroying its customer's list.
 */
class SmtpRecipientProber implements RecipientProber
{
    /**
     * The address used in MAIL FROM.
     *
     * RFC 5321 §4.5.1 requires a syntactically valid reverse-path, and RFC 5321
     * §6.2.3 allows the null sender precisely so that a check need not claim to
     * be from anywhere. `postmaster@` is used rather than the empty null path
     * because a small number of servers reject the null form outright, and the
     * address is never used to send anything.
     */
    private const PROBE_SENDER = 'postmaster@invalid.example';

    public function __construct(
        private readonly int $timeoutSeconds,
    ) {}

    public static function fromConfiguration(): self
    {
        return new self((int) config('sender.validation.smtp_timeout_seconds', 5));
    }

    public function probe(string $host, string $address): RecipientProbeResult
    {
        if ($this->timeoutSeconds < 1) {
            return RecipientProbeResult::unreachable(ValidationReason::VerificationBlocked);
        }

        $endpoint = $this->endpointFor($host);

        if ($endpoint === null) {
            // Refused before any socket is opened, for the same reason the URL
            // fetcher refuses: the platform must not become a way to probe
            // services on the host's own network.
            return RecipientProbeResult::unreachable(ValidationReason::VerificationBlocked);
        }

        $connection = @stream_socket_client(
            'tcp://'.$endpoint,
            $errorNumber,
            $errorMessage,
            $this->timeoutSeconds,
            STREAM_CLIENT_CONNECT,
        );

        if ($connection === false) {
            return RecipientProbeResult::unreachable($this->transportFailure($errorNumber));
        }

        try {
            stream_set_timeout($connection, $this->timeoutSeconds);

            return $this->converse($connection, $address);
        } finally {
            // The socket is this method's to close, on every path including an
            // exception thrown mid-conversation. One leaked handle per address
            // would exhaust a worker long before a large list finished.
            if (is_resource($connection)) {
                @fclose($connection);
            }
        }
    }

    /**
     * The conversation, up to RCPT TO and no further.
     */
    private function converse($connection, string $address): RecipientProbeResult
    {
        $greeting = $this->read($connection);

        if ($greeting === null) {
            return RecipientProbeResult::unreachable(ValidationReason::Timeout);
        }

        $this->write($connection, 'EHLO '.self::localDomain());

        if ($this->read($connection) === null) {
            return RecipientProbeResult::unreachable(ValidationReason::Timeout);
        }

        $this->write($connection, 'MAIL FROM:<'.self::PROBE_SENDER.'>');

        if ($this->read($connection) === null) {
            return RecipientProbeResult::unreachable(ValidationReason::Timeout);
        }

        $this->write($connection, 'RCPT TO:<'.$address.'>');

        $reply = $this->read($connection);

        if ($reply === null) {
            return RecipientProbeResult::unreachable(ValidationReason::Timeout);
        }

        // RSET rather than letting the connection drop mid-transaction: a server
        // left holding an accepted MAIL FROM and an accepted RCPT TO has state it
        // expects the client to finish or abandon deliberately, and a client that
        // simply disappears is the behaviour receiving providers notice.
        $this->write($connection, 'RSET');
        $this->write($connection, 'QUIT');

        [$code, $enhanced] = $this->parse($reply);

        if ($code === null) {
            return RecipientProbeResult::unreachable(ValidationReason::ProviderProtection);
        }

        return RecipientProbeResult::replied($code, $enhanced);
    }

    /**
     * A single SMTP reply line, with its code and enhanced status.
     *
     * The text is discarded here rather than carried onwards: responses quote the
     * address and often the rejected identity, and nothing downstream of the
     * classification needs the wording.
     *
     * @return array{0: int|null, 1: string|null}
     */
    private function parse(string $reply): array
    {
        // Multi-line replies use `250-` continuations and end with `250 `.
        $reply = trim($reply);

        if (preg_match('/^(\d{3})[- ]/', $reply, $matches) !== 1) {
            return [null, null];
        }

        $code = (int) $matches[1];

        // Enhanced status: `550-5.1.1 text`, `550 5.1.1 text`, or absent.
        $enhanced = preg_match('/^\d{3}[- ](\d\.\d{1,3}\.\d{1,3})/', $reply, $status) === 1
            ? $status[1]
            : null;

        return [$code, $enhanced];
    }

    /**
     * One reply, or null if the socket did not answer in time.
     */
    private function read($connection): ?string
    {
        $line = @fgets($connection, 1024);

        if ($line === false || $line === null) {
            return null;
        }

        // Drain any continuation lines so the next command is not read against
        // the tail of the previous reply.
        while (preg_match('/^\d{3}-/', $line) === 1) {
            $next = @fgets($connection, 1024);

            if ($next === false || $next === null) {
                break;
            }

            $line = $next;
        }

        return $line;
    }

    private function write($connection, string $line): void
    {
        @fwrite($connection, $line."\r\n");
    }

    /**
     * The address to connect to, after resolving and checking it.
     *
     * The resolved address is returned rather than the name, and connected to
     * directly, so the address that was checked is the address that is dialled.
     * Re-resolving at connect time is the standard TOCTOU mistake and would let a
     * name that validated as public resolve again as something else.
     */
    private function endpointFor(string $host): ?string
    {
        $host = trim($host);

        if ($host === '') {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return IpPolicy::isGloballyRoutable($host) ? $host.':25' : null;
        }

        if (preg_match('/^[a-z0-9.-]+$/i', $host) !== 1) {
            return null;
        }

        // Every resolved address is checked, not just the first, and one private
        // address refuses the whole host — the same rule the URL fetcher applies.
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        $addresses = [];

        if (is_array($records)) {
            foreach ($records as $record) {
                $address = $record['ip'] ?? $record['ipv6'] ?? null;

                if (is_string($address)) {
                    $addresses[] = $address;
                }
            }
        }

        if ($addresses === []) {
            return null;
        }

        foreach ($addresses as $address) {
            if (! IpPolicy::isGloballyRoutable($address)) {
                return null;
            }
        }

        return $addresses[0].':25';
    }

    /**
     * What a refused connection actually tells us.
     *
     * Every one of these is `UNKNOWN`. The distinction is kept only so the reason
     * code on a report is accurate about *why* nothing could be learned — the
     * classification is the same in all cases, and none of them is evidence.
     */
    private function transportFailure(int $errorNumber): ValidationReason
    {
        return $errorNumber === SOCKET_ETIMEDOUT || $errorNumber === 110
            ? ValidationReason::Timeout
            : ValidationReason::DnsUnavailable;
    }

    /**
     * The name announced in EHLO.
     *
     * Falls back to a reserved `.invalid` name when the application URL is
     * unset, because announcing nothing is not permitted and announcing the
     * platform's real hostname to a stranger's mail server is not required.
     */
    private static function localDomain(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'sender-platform.invalid';
    }
}

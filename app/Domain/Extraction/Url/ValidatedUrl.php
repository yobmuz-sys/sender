<?php

declare(strict_types=1);

namespace App\Domain\Extraction\Url;

/**
 * A URL that has passed every check that does not require touching the network.
 *
 * Produced before any DNS lookup, so a URL that is unacceptable on its face
 * costs nothing. The host has *not* been resolved here — that is the fetcher's
 * job, and it must happen after this validation so the two cannot be reordered
 * by a future caller into resolving something unvalidated.
 */
final readonly class ValidatedUrl
{
    /**
     * @param  string  $scheme  http or https
     * @param  int  $port  the effective port, defaulted from the scheme
     * @param  bool  $hostIsIpLiteral  when true the host is already an address
     *                                 and must not be resolved
     */
    public function __construct(
        public string $scheme,
        public string $host,
        public int $port,
        public string $path,
        public bool $hostIsIpLiteral = false,
    ) {}

    /**
     * Host and port, in the form libcurl's CURLOPT_RESOLVE expects.
     */
    public function resolveEntry(string $ip): string
    {
        return sprintf('%s:%d:%s', $this->host, $this->port, $ip);
    }

    /**
     * The absolute URL, reconstructed rather than carried from input.
     *
     * Rebuilding matters: it means the string shown to a customer and the
     * string used for the request are the same object, so a URL cannot be
     * displayed as one thing and fetched as another.
     */
    public function toUrl(): string
    {
        $defaultPort = ($this->scheme === 'http' && $this->port === 80)
            || ($this->scheme === 'https' && $this->port === 443);

        return sprintf(
            '%s://%s%s%s',
            $this->scheme,
            $this->host,
            $defaultPort ? '' : ':'.$this->port,
            $this->path,
        );
    }

    /**
     * A display form with any query string removed.
     *
     * Queries routinely carry tokens, session identifiers and email addresses.
     * The stored `source_ref` is what an operator and a customer both see, so it
     * must not be a place secrets accumulate.
     */
    public function toDisplayUrl(): string
    {
        $url = $this->toUrl();

        return str_contains($url, '?')
            ? substr($url, 0, (int) strpos($url, '?'))
            : $url;
    }

    /**
     * The scheme default for the port, used when a URL omits it.
     */
    public function defaultPortFor(string $scheme): int
    {
        return $scheme === 'https' ? 443 : 80;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Extraction\Url;

/**
 * Decides whether a URL is acceptable at all, before any network is touched.
 *
 * Everything checked here is a property of the string the user submitted. The
 * fetcher applies it again to every redirect target, so a safe first hop cannot
 * be used to smuggle an unsafe second one past it.
 *
 * The order is deliberate: length first, because a hostile string should not
 * make the parser do work before it is known to be within bounds; then scheme,
 * then credentials, then port. Each refusal names its own category, because
 * "invalid URL" tells a user nothing they can act on while "only http and https
 * can be fetched" does.
 */
final class UrlValidator
{
    private const ALLOWED_SCHEMES = ['http', 'https'];

    public function __construct(
        private readonly int $maxLength,
        /** @var list<int> */
        private readonly array $allowedPorts,
    ) {}

    public static function fromConfiguration(): self
    {
        return new self(
            maxLength: (int) config('sender.url_fetch.max_url_length', 2048),
            allowedPorts: array_map('intval', (array) config('sender.url_fetch.allowed_ports', [80, 443])),
        );
    }

    /**
     * @throws UrlFetchException
     */
    public function validate(string $url): ValidatedUrl
    {
        $url = trim($url);

        if ($url === '') {
            throw UrlFetchException::of(UrlFailureReason::InvalidUrl);
        }

        if (mb_strlen($url) > $this->maxLength) {
            // Checked on the raw input, before parsing, so an oversized string
            // cannot make the parser work.
            throw UrlFetchException::of(UrlFailureReason::InvalidUrl);
        }

        $parts = parse_url($url);

        if ($parts === false) {
            throw UrlFetchException::of(UrlFailureReason::InvalidUrl);
        }

        // The scheme is checked before the host, because a scheme like `data:`
        // has no host at all — testing for one first would report every such
        // URL as unparseable and lose the reason the user needs to see.
        if (! isset($parts['scheme'])) {
            throw UrlFetchException::of(UrlFailureReason::InvalidUrl);
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            // Covers file:, ftp:, gopher:, data: and javascript:, none of which
            // have a legitimate business being fetched by an email extractor.
            throw UrlFetchException::of(UrlFailureReason::UnsupportedScheme);
        }

        if (! isset($parts['host']) || $parts['host'] === '') {
            throw UrlFetchException::of(UrlFailureReason::InvalidUrl);
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            // Nothing this version fetches needs credentials, and accepting them
            // would turn the platform into something that can log into a third
            // party service on a user's behalf — and into one that will happily
            // send them wherever a redirect says.
            throw UrlFetchException::of(UrlFailureReason::CredentialsInUrl);
        }

        $host = strtolower(trim($parts['host'], '.'));
        $host = $this->stripIpv6Brackets($host);

        if ($host === '') {
            throw UrlFetchException::of(UrlFailureReason::InvalidUrl);
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        if (! in_array($port, $this->allowedPorts, true)) {
            // Restricting to the web ports is what stops this being a port
            // scanner for whatever else the host happens to be running.
            throw UrlFetchException::of(UrlFailureReason::UnsupportedPort);
        }

        return new ValidatedUrl(
            scheme: $scheme,
            host: $host,
            port: $port,
            path: ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : ''),
            hostIsIpLiteral: filter_var($host, FILTER_VALIDATE_IP) !== false,
        );
    }

    private function stripIpv6Brackets(string $host): string
    {
        return str_starts_with($host, '[') && str_ends_with($host, ']')
            ? substr($host, 1, -1)
            : $host;
    }
}

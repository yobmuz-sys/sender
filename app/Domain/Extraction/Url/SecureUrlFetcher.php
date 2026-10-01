<?php

declare(strict_types=1);

namespace App\Domain\Extraction\Url;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Fetches a URL under a policy designed to stop it reaching anything it should
 * not.
 *
 * A URL here is supplied by a third party, and the application is a server with
 * a trusted network position. That combination is the whole problem: the value
 * of asking a server to fetch `http://internal-service/` is that the server can
 * reach it and the requester cannot. So this class exists to make sure that
 * request, and every variant of it, is refused.
 *
 * The controls, and what each is for:
 *
 *   - scheme, credentials, port   a URL unacceptable on its face is refused
 *                                 before any lookup happens
 *   - every resolved address       checked for global reachability; one private
 *                                 answer refuses the whole host
 *   - IP pinning                   the validated address is bound to the
 *                                 connection via CURLOPT_RESOLVE, closing the
 *                                 rebinding window between check and use
 *   - manual redirects             each hop revalidated from scratch, so a safe
 *                                 first response cannot launder an unsafe
 *                                 second one
 *   - streaming with a byte ceiling  the limit applies while the body is written
 *   - content type                 this extracts addresses from text; it is not a
 *                                 general downloader
 *   - TLS verification left on     disabling it would make the pinning above
 *                                 pointless, since the peer would not be checked
 *
 * It is not a general HTTP client. Every refusal is a decision about what this
 * platform will reach, not about what HTTP can do.
 */
final class SecureUrlFetcher
{
    public function __construct(
        private readonly UrlValidator $validator,
        private readonly DnsResolver $resolver,
    ) {}

    public static function make(): self
    {
        return new self(UrlValidator::fromConfiguration(), new DnsResolver);
    }

    /**
     * Fetch and validate, returning the body in a temporary file.
     *
     * @throws UrlFetchException
     */
    public function fetch(string $url): FetchedResource
    {
        $current = $this->validator->validate($url);
        $maxRedirects = (int) config('sender.url_fetch.max_redirects', 3);

        // The counter is requests made, so the first request is bounded by the
        // same ceiling as the rest and the loop cannot run unbounded.
        for ($made = 0; $made <= $maxRedirects; $made++) {
            $outcome = $this->attempt($current, $maxRedirects - $made);

            if ($outcome instanceof FetchedResource) {
                return $outcome;
            }

            // A redirect. Its target has already been validated, and will be
            // resolved and pinned again on the next iteration.
            $current = $outcome;
        }

        throw UrlFetchException::of(UrlFailureReason::RedirectLimit);
    }

    /**
     * One request.
     *
     * Returns a {@see FetchedResource} on success, or the validated target of a
     * redirect to follow.
     *
     *
     * @throws UrlFetchException
     */
    private function attempt(ValidatedUrl $url, int $redirectsRemaining): FetchedResource|ValidatedUrl
    {
        // Resolved and pinned before the request, not after.
        $address = $this->resolver->resolvePublicAddress($url);

        $path = $this->temporaryPath();
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw UrlFetchException::of(UrlFailureReason::InvalidUrl, 'could not open a temporary file');
        }

        try {
            $response = $this->send($url, $address, $handle, $this->maxResponseBytes());
        } catch (Throwable $exception) {
            fclose($handle);
            @unlink($path);

            throw $this->translate($exception);
        }

        fclose($handle);

        try {
            return $this->interpret($response, $path, $url, $redirectsRemaining);
        } catch (Throwable $exception) {
            // Every failure path removes the partial file. A half-written
            // response left in the temp directory is a leak, and the next run
            // would not know it was incomplete.
            @unlink($path);

            throw $exception;
        }
    }

    /**
     * The request itself.
     *
     * @param  resource  $sink
     */
    private function send(ValidatedUrl $url, string $address, $sink, int $maxBytes): Response
    {
        return Http::withOptions([
            'allow_redirects' => false,

            'curl' => [
                // The validated address, bound to this host and port for this
                // connection. libcurl therefore does not resolve the name again
                // when it opens the socket, which is what closes the rebinding
                // window: the connection goes to the address that was checked,
                // while the Host header and TLS SNI still carry the original
                // hostname so certificates validate as normal.
                CURLOPT_RESOLVE => [$url->resolveEntry($address)],

                // The response ceiling, enforced *during* the transfer.
                //
                // Checking the size afterwards bounds nothing: a server that
                // keeps sending would fill the disk before any check ran. A
                // non-zero return aborts the transfer mid-download, which is
                // the only point at which a limit on bytes actually bounds
                // bytes.
                //
                // libcurl calls this with ($ch, $downloadSize, $downloaded,
                // $uploadSize, $uploaded). The third argument is the count
                // received *so far*; the second is only the total, which a
                // chunked response may not know. Comparing the wrong one would
                // abort on the size of a Content-Length rather than the size
                // of the body, and would let a lying header through.
                CURLOPT_NOPROGRESS => false,
                CURLOPT_PROGRESSFUNCTION => static function (
                    $handle,
                    int $downloadTotal,
                    int $downloaded,
                    int $uploadTotal,
                    int $uploaded,
                ) use ($maxBytes): int {
                    return $downloaded > $maxBytes ? 1 : 0;
                },
            ],
        ])
            ->connectTimeout((int) config('sender.url_fetch.connect_timeout_seconds', 5))
            ->timeout((int) config('sender.url_fetch.request_timeout_seconds', 10))
            ->withHeaders([
                'User-Agent' => 'Sender/1.0 (+email-extraction)',
                'Accept' => 'text/html,application/xhtml+xml,text/plain;q=0.9',
                // Declared identity so the byte ceiling means what it says: a
                // compressed response would otherwise arrive as a small number
                // of bytes and expand to far more.
                'Accept-Encoding' => 'identity',
            ])
            ->sink($sink)
            ->send($url->scheme, $url->toUrl());
    }

    private function maxResponseBytes(): int
    {
        return max(1, (int) config('sender.url_fetch.max_response_bytes', 2 * 1024 * 1024));
    }

    /**
     * Decide what a response means.
     *
     *
     * @throws UrlFetchException
     */
    private function interpret(
        Response $response,
        string $path,
        ValidatedUrl $url,
        int $redirectsRemaining,
    ): FetchedResource|ValidatedUrl {
        $status = $response->status();

        if ($status >= 300 && $status < 400) {
            $location = $response->header('Location');

            if ($location === '') {
                throw UrlFetchException::of(UrlFailureReason::HttpError, 'redirect without a Location header');
            }

            if ($redirectsRemaining <= 0) {
                throw UrlFetchException::of(UrlFailureReason::RedirectLimit);
            }

            // Revalidated in full — scheme, credentials, port — and resolved and
            // address-checked on the next iteration.
            return $this->validator->validate($this->absoluteUrl($url, $location));
        }

        if ($status < 200 || $status >= 300) {
            // An error page is not content. Extracting addresses from a 404
            // body would report results from a page that does not exist.
            throw UrlFetchException::of(UrlFailureReason::HttpError, 'HTTP '.$status);
        }

        $this->assertContentTypeAllowed($response);
        $this->assertDeclaredLengthWithinLimit($response);

        clearstatcache(true, $path);
        $bytes = (int) filesize($path);

        if ($bytes > $this->maxResponseBytes()) {
            throw UrlFetchException::of(UrlFailureReason::ResponseTooLarge);
        }

        // A response that declared a body and produced none means the transfer
        // was cut short without being reported as failed. Left alone, this would
        // complete an extraction with no results and no explanation, which is
        // worse than an honest failure.
        $declared = $response->header('Content-Length');

        if ($declared !== '' && is_numeric($declared) && (int) $declared > 0 && $bytes === 0) {
            throw UrlFetchException::of(
                UrlFailureReason::RequestTimeout,
                'the transfer ended without delivering the body it declared',
            );
        }

        return new FetchedResource(
            path: $path,
            bytes: $bytes,
            contentType: strtolower(trim(explode(';', $response->header('Content-Type'))[0])),
            finalUrl: $url->toUrl(),
        );
    }

    /**
     * @throws UrlFetchException
     */
    private function assertContentTypeAllowed(Response $response): void
    {
        $type = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
        $allowed = array_map('strtolower', (array) config('sender.url_fetch.allowed_content_types', []));

        if ($type === '' || ! in_array($type, $allowed, true)) {
            // Refused on the declared type, before the body is read. This
            // workload extracts addresses from text; an archive or an image is
            // not a page, and accepting it would make this a file downloader.
            throw UrlFetchException::of(UrlFailureReason::UnsupportedContentType, 'content type: '.$type);
        }
    }

    /**
     * Reject on the declared length before reading anything.
     *
     * A server's claim is only a claim, but honouring it avoids downloading a
     * body already known to be too large — which is the difference between
     * refusing an oversized response and receiving it.
     */
    private function assertDeclaredLengthWithinLimit(Response $response): void
    {
        $declared = $response->header('Content-Length');

        if ($declared === '' || ! is_numeric($declared)) {
            return;
        }

        if ((int) $declared > (int) config('sender.url_fetch.max_response_bytes', 2 * 1024 * 1024)) {
            throw UrlFetchException::of(UrlFailureReason::ResponseTooLarge, 'declared '.$declared.' bytes');
        }
    }

    /**
     * Resolve a possibly relative Location header against the current URL.
     *
     * A Location carrying *any* scheme is passed through untouched so the
     * validator can refuse it. Treating `file:///etc/passwd` as a relative path
     * would rewrite it into an ordinary https URL on the current host and then
     * follow it — which is how a redirect filter gets bypassed by a server
     * that simply answers with a different scheme.
     */
    private function absoluteUrl(ValidatedUrl $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $location) === 1) {
            return $location;
        }

        $origin = $base->scheme.'://'.$base->host
            .(($base->scheme === 'http' && $base->port === 80)
                || ($base->scheme === 'https' && $base->port === 443)
                ? '' : ':'.$base->port);

        return str_starts_with($location, '/')
            ? $origin.$location
            : $origin.'/'.ltrim($location, '/');
    }

    /**
     * Map a transport failure onto a category a user can act on.
     *
     * Prefers libcurl's numeric error code over the message text. The text is a
     * human-readable rendering that varies between versions and locales, so
     * matching on it produces a category that quietly changes meaning; the
     * number is the actual condition.
     */
    private function translate(Throwable $exception): UrlFetchException
    {
        $detail = $exception->getMessage();
        $errno = $this->curlErrno($exception);

        $reason = match ($errno) {
            42 => UrlFailureReason::ResponseTooLarge,  // aborted by the byte ceiling
            28 => UrlFailureReason::RequestTimeout,     // operation timed out
            6, 7 => UrlFailureReason::DnsFailure,       // could not resolve host
            35, 51, 58, 60, 77, 83 => UrlFailureReason::TlsFailure,
            default => null,
        };

        if ($reason === null && $exception instanceof ConnectionException) {
            // No usable code, so fall back to the wording. Only as a fallback —
            // a heuristic that silently reclassifies failures is worse than a
            // less precise but stable category.
            $reason = match (true) {
                stripos($detail, 'timed out') !== false, stripos($detail, 'timeout') !== false => stripos($detail, 'connect') !== false
                        ? UrlFailureReason::ConnectionTimeout
                        : UrlFailureReason::RequestTimeout,
                str_contains(strtolower($detail), 'ssl')
                    || str_contains(strtolower($detail), 'tls')
                    || str_contains(strtolower($detail), 'certificate') => UrlFailureReason::TlsFailure,
                default => UrlFailureReason::RequestTimeout,
            };
        }

        return UrlFetchException::of($reason ?? UrlFailureReason::RequestTimeout, $detail, $exception);
    }

    /**
     * libcurl's error number, when the exception carries one.
     */
    private function curlErrno(Throwable $exception): ?int
    {
        if (! method_exists($exception, 'getHandlerContext')) {
            return null;
        }

        $context = $exception->getHandlerContext();

        return is_array($context) && isset($context['errno']) ? (int) $context['errno'] : null;
    }

    private function temporaryPath(): string
    {
        $dir = rtrim((string) config('sender.url_fetch.temp_directory', sys_get_temp_dir()), DIRECTORY_SEPARATOR);

        if ($dir === '' || ! is_dir($dir) || ! is_writable($dir)) {
            $dir = sys_get_temp_dir();
        }

        return $dir.DIRECTORY_SEPARATOR.'sender-url-'.bin2hex(random_bytes(16)).'.tmp';
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Extraction\Url\DnsResolver;
use App\Domain\Extraction\Url\SecureUrlFetcher;
use App\Domain\Extraction\Url\UrlFailureReason;
use App\Domain\Extraction\Url\UrlFetchException;
use App\Domain\Extraction\Url\UrlValidator;
use App\Domain\Extraction\Url\ValidatedUrl;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The fetcher's behaviour against a faked transport.
 *
 * The transport is faked, but the policy is not: scheme, port and content-type
 * checks, redirect revalidation, and the response-size ceiling all run for real
 * against these responses. Only the socket is simulated.
 *
 * That distinction matters. A test that mocked the fetcher would pass whether or
 * not it refuses a redirect to 127.0.0.1, which is the entire question.
 */
class SecureUrlFetcherTest extends TestCase
{
    #[Test]
    public function a_text_response_is_streamed_to_a_temporary_file(): void
    {
        Http::fake([
            'example.com/*' => Http::response(
                '<html><body><a href="mailto:someone@example.com">contact</a></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8'],
            ),
        ]);

        $resource = SecureUrlFetcher::make()->fetch('https://example.com/contact');

        try {
            // On disk, not in memory and not in the database.
            $this->assertFileExists($resource->path);
            $this->assertGreaterThan(0, $resource->bytes);
            $this->assertStringContainsString('someone@example.com', (string) file_get_contents($resource->path));
            $this->assertSame('text/html', $resource->contentType);
        } finally {
            $resource->remove();
        }

        $this->assertFileDoesNotExist($resource->path);
    }

    #[Test]
    public function a_plain_text_response_is_accepted(): void
    {
        Http::fake([
            'example.com/*' => Http::response('write to info@example.com', 200, ['Content-Type' => 'text/plain']),
        ]);

        $resource = SecureUrlFetcher::make()->fetch('https://example.com/list.txt');

        $resource->remove();

        $this->assertSame('text/plain', $resource->contentType);
    }

    #[Test]
    public function an_xhtml_response_is_accepted(): void
    {
        Http::fake([
            'example.com/*' => Http::response('<html/>', 200, ['Content-Type' => 'application/xhtml+xml']),
        ]);

        $resource = SecureUrlFetcher::make()->fetch('https://example.com/');

        $resource->remove();

        $this->assertSame('application/xhtml+xml', $resource->contentType);
    }

    #[Test]
    public function a_binary_content_type_is_refused_without_being_read(): void
    {
        // This workload extracts addresses from text. Accepting octet-streams
        // and archives would make the platform a general file downloader.
        foreach ([
            'application/octet-stream',
            'application/zip',
            'image/png',
            'video/mp4',
            'audio/mpeg',
            'application/pdf',
        ] as $contentType) {
            Http::fake([
                'example.com/*' => Http::response('binary', 200, ['Content-Type' => $contentType]),
            ]);

            $this->assertReason(UrlFailureReason::UnsupportedContentType, 'https://example.com/file', $contentType);
        }
    }

    #[Test]
    public function a_server_error_is_a_failure_not_content(): void
    {
        // An error page is not a page. Extracting from a 500 body would report
        // addresses from something that does not exist.
        foreach ([400, 401, 403, 404, 500, 502, 503] as $status) {
            Http::fake([
                'example.com/*' => Http::response(
                    '<html>support@example.com</html>',
                    $status,
                    ['Content-Type' => 'text/html'],
                ),
            ]);

            $this->assertReason(UrlFailureReason::HttpError, 'https://example.com/', 'HTTP '.$status);
        }
    }

    #[Test]
    public function one_safe_redirect_is_followed(): void
    {
        Http::fake([
            'example.com/old' => Http::response('', 301, ['Location' => 'https://example.com/new']),
            'example.com/new' => Http::response(
                '<html>hello@example.com</html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $resource = SecureUrlFetcher::make()->fetch('https://example.com/old');

        $this->assertStringContainsString('hello@example.com', (string) file_get_contents($resource->path));

        $resource->remove();
    }

    #[Test]
    public function several_redirects_within_the_limit_are_followed(): void
    {
        Http::fake([
            'example.com/a' => Http::response('', 302, ['Location' => 'https://example.com/b']),
            'example.com/b' => Http::response('', 302, ['Location' => 'https://example.com/c']),
            'example.com/c' => Http::response('', 302, ['Location' => 'https://example.com/d']),
            'example.com/d' => Http::response(
                '<html>final@example.com</html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $resource = SecureUrlFetcher::make()->fetch('https://example.com/a');

        $this->assertStringContainsString('final@example.com', (string) file_get_contents($resource->path));

        $resource->remove();
    }

    #[Test]
    public function a_redirect_beyond_the_configured_limit_is_refused(): void
    {
        config()->set('sender.url_fetch.max_redirects', 2);

        Http::fake([
            'example.com/*' => Http::response('', 302, ['Location' => 'https://example.com/next']),
        ]);

        $this->assertReason(UrlFailureReason::RedirectLimit, 'https://example.com/start');
    }

    #[Test]
    public function a_redirect_to_a_private_destination_is_refused(): void
    {
        // The point of revalidating every hop: a safe first response must not
        // be able to launder an unsafe second one.
        Http::fake([
            'example.com/*' => Http::response('', 302, ['Location' => 'http://10.0.0.5/admin']),
        ]);

        $this->assertReason(UrlFailureReason::BlockedDestination, 'https://example.com/');
    }

    #[Test]
    public function a_redirect_to_loopback_is_refused(): void
    {
        Http::fake([
            'example.com/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1:80/']),
        ]);

        $this->assertReason(UrlFailureReason::BlockedDestination, 'https://example.com/');
    }

    #[Test]
    public function a_redirect_to_the_metadata_endpoint_is_refused(): void
    {
        Http::fake([
            'example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
        ]);

        $this->assertReason(UrlFailureReason::BlockedDestination, 'https://example.com/');
    }

    #[Test]
    public function a_redirect_to_an_unsupported_scheme_is_refused(): void
    {
        Http::fake([
            'example.com/*' => Http::response('', 302, ['Location' => 'file:///etc/passwd']),
        ]);

        $this->assertReason(UrlFailureReason::UnsupportedScheme, 'https://example.com/');
    }

    #[Test]
    public function a_redirect_to_credentials_is_refused(): void
    {
        Http::fake([
            'example.com/*' => Http::response('', 302, ['Location' => 'http://user:pass@other.example/']),
        ]);

        $this->assertReason(UrlFailureReason::CredentialsInUrl, 'https://example.com/');
    }

    #[Test]
    public function a_declared_content_length_over_the_limit_is_refused_without_downloading(): void
    {
        config()->set('sender.url_fetch.max_response_bytes', 1024);

        Http::fake([
            'example.com/*' => Http::response('x', 200, [
                'Content-Type' => 'text/html',
                // Claims to be far larger than the ceiling.
                'Content-Length' => (string) (5 * 1024 * 1024),
            ]),
        ]);

        $this->assertReason(UrlFailureReason::ResponseTooLarge, 'https://example.com/big');
    }

    #[Test]
    public function a_response_larger_than_the_limit_is_refused(): void
    {
        config()->set('sender.url_fetch.max_response_bytes', 1024);

        Http::fake([
            'example.com/*' => Http::response(str_repeat('a', 8 * 1024), 200, ['Content-Type' => 'text/plain']),
        ]);

        $this->assertReason(UrlFailureReason::ResponseTooLarge, 'https://example.com/flood');
    }

    #[Test]
    public function the_byte_ceiling_aborts_the_transfer_rather_than_truncating_it(): void
    {
        // The limit has to hold *while* the body is written. A server that
        // keeps sending would otherwise fill the disk before any check ran, and
        // a response truncated to fit would be silently incomplete — which is
        // worse than refusing it, because the results would look complete.
        //
        // Asserted on the callback libcurl actually uses, rather than on a
        // faked transfer: the fake handler does not stream, so a test that
        // claimed to observe mid-download abandonment through it would be
        // asserting nothing.
        config()->set('sender.url_fetch.max_response_bytes', 1024);

        $captured = null;

        Http::fake([
            'example.com/*' => Http::response('small', 200, ['Content-Type' => 'text/plain']),
        ]);

        $this->captureOptions($captured);

        try {
            $resource = SecureUrlFetcher::make()->fetch('https://example.com/');
            $resource->remove();
        } catch (UrlFetchException) {
            // Expected only if the fake aborts; the assertions below are the
            // real test.
        }

        $callback = $captured['curl'][CURLOPT_PROGRESSFUNCTION] ?? null;

        $this->assertNotNull($callback, 'the request must carry a progress callback');

        // Called the way libcurl calls it: ($ch, $downloadSize, $downloaded,
        // $uploadSize, $uploaded). Returning non-zero aborts the transfer.
        $this->assertSame(0, $callback(null, 0, 1024, 0, 0), 'at the ceiling, transfer continues');
        $this->assertNotSame(0, $callback(null, 0, 1025, 0, 0), 'past the ceiling, transfer aborts');
        $this->assertNotSame(0, $callback(null, 0, 10 * 1024 * 1024, 0, 0), 'far past the ceiling, transfer aborts');
    }

    #[Test]
    public function a_response_that_declares_a_body_and_delivers_none_is_a_failure(): void
    {
        // Guard against the silent case: a transfer cut short without being
        // reported as failed would otherwise complete an extraction with no
        // results and no explanation.
        Http::fake([
            'example.com/*' => Http::response('', 200, [
                'Content-Type' => 'text/html',
                'Content-Length' => '4096',
            ]),
        ]);

        $this->assertReason(UrlFailureReason::RequestTimeout, 'https://example.com/truncated');
    }

    #[Test]
    public function a_failed_fetch_leaves_no_temporary_file_behind(): void
    {
        config()->set('sender.url_fetch.temp_directory', sys_get_temp_dir());

        Http::fake([
            'example.com/*' => Http::response('binary', 200, ['Content-Type' => 'application/octet-stream']),
        ]);

        $before = $this->temporaryFileCount();

        try {
            SecureUrlFetcher::make()->fetch('https://example.com/file');
        } catch (UrlFetchException) {
            // Expected.
        }

        // A partial or complete body left behind would leak one file per
        // attempt, across every retry of every job.
        $this->assertSame($before, $this->temporaryFileCount());
    }

    #[Test]
    public function a_failed_size_limited_fetch_leaves_no_temporary_file_behind(): void
    {
        config()->set('sender.url_fetch.max_response_bytes', 256);
        config()->set('sender.url_fetch.temp_directory', sys_get_temp_dir());

        Http::fake([
            'example.com/*' => Http::response(str_repeat('a', 16 * 1024), 200, ['Content-Type' => 'text/plain']),
        ]);

        $before = $this->temporaryFileCount();

        try {
            SecureUrlFetcher::make()->fetch('https://example.com/flood');
        } catch (UrlFetchException) {
            // Expected.
        }

        $this->assertSame($before, $this->temporaryFileCount());
    }

    #[Test]
    public function the_validated_address_is_pinned_to_the_connection(): void
    {
        // The defect this exists to prevent: resolving the name, checking it,
        // and then letting the HTTP client resolve it *again*. Between the two
        // lookups an attacker who controls DNS can answer with a public address
        // and then with a loopback one.
        //
        // Observed through request middleware, which is the only place the real
        // Guzzle options are visible.
        Http::fake([
            'example.com/*' => Http::response('<html/>', 200, ['Content-Type' => 'text/html']),
        ]);

        $options = null;
        $this->captureOptions($options);

        $resource = $this->fetcherWithFixedAddress()->fetch('https://example.com/');

        $resource->remove();

        $this->assertNotNull($options, 'no request was made');

        $this->assertArrayHasKey(
            CURLOPT_RESOLVE,
            $options['curl'] ?? [],
            'without CURLOPT_RESOLVE the connection would resolve the hostname again',
        );

        // host:port:ip, naming the address that was validated.
        $this->assertSame(['example.com:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);

        // The hostname is still what is sent, so Host and TLS SNI are unaffected by
        // the pinning — the socket goes to the address, not the name.
        Http::assertSent(function (Request $request): bool {
            $this->assertSame('example.com', $request->toPsrRequest()->getUri()->getHost());

            return true;
        });
    }

    #[Test]
    public function automatic_redirect_following_is_disabled(): void
    {
        // If the client followed redirects itself, each hop would be requested
        // under the previous hop's pinned address, reaching a host nobody
        // validated for that connection.
        Http::fake([
            'example.com/*' => Http::response('', 302, ['Location' => 'https://example.com/next']),
        ]);

        $options = null;
        $this->captureOptions($options);

        try {
            SecureUrlFetcher::make()->fetch('https://example.com/');
        } catch (UrlFetchException) {
            // Expected: this loops to the configured redirect limit.
        }

        $this->assertNotNull($options, 'no request was made');
        $this->assertFalse(
            $options['allow_redirects'] ?? true,
            'redirects must be handled manually so each hop is revalidated',
        );
    }

    #[Test]
    public function each_hop_is_a_separate_request_rather_than_an_internal_follow(): void
    {
        config()->set('sender.url_fetch.max_redirects', 2);

        Http::fake([
            'example.com/a' => Http::response('', 302, ['Location' => 'https://example.com/b']),
            'example.com/b' => Http::response('', 302, ['Location' => 'https://example.com/c']),
            'example.com/c' => Http::response('end@example.com', 200, ['Content-Type' => 'text/plain']),
        ]);

        $resource = SecureUrlFetcher::make()->fetch('https://example.com/a');

        $this->assertStringContainsString('end@example.com', (string) file_get_contents($resource->path));

        $resource->remove();

        // Three separate requests, one per hop, each revalidated. A client
        // following redirects internally would have produced one.
        Http::assertSentCount(3);
    }

    /**
     * A fetcher with a fixed DNS answer, so pinning can be asserted without a
     * real lookup. Validation, limits and redirects all still run for real.
     */
    private function fetcherWithFixedAddress(): SecureUrlFetcher
    {
        return new SecureUrlFetcher(
            UrlValidator::fromConfiguration(),
            new class extends DnsResolver
            {
                public function resolvePublicAddress(ValidatedUrl $url): string
                {
                    return '93.184.216.34';
                }
            },
        );
    }

    /**
     * Capture the options of the first request the client actually makes.
     *
     * @return array<string, mixed>|null
     */
    private function captureOptions(&$captured): void
    {
        // Full handler middleware rather than `globalRequestMiddleware`, which
        // maps to Guzzle's `mapRequest` and receives only the request — not the
        // options, which is the whole thing under assertion here.
        Http::globalMiddleware(function ($handler) use (&$captured) {
            return function ($request, array $options) use ($handler, &$captured) {
                $captured ??= $options;

                return $handler($request, $options);
            };
        });
    }

    private function temporaryFileCount(): int
    {
        $files = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'sender-url-*.tmp');

        return $files === false ? 0 : count($files);
    }

    private function assertReason(UrlFailureReason $expected, string $url, string $context = ''): void
    {
        try {
            $resource = SecureUrlFetcher::make()->fetch($url);
            $resource->remove();
        } catch (UrlFetchException $exception) {
            $this->assertSame(
                $expected->value,
                $exception->reason->value,
                $context !== '' ? $context.' should have been refused as '.$expected->value : '',
            );

            return;
        }

        $this->fail(($context !== '' ? $context : $url).' should have been refused as '.$expected->value);
    }
}

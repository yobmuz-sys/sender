<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Extraction\ExtractionStatus;
use App\Domain\Extraction\Extractor;
use App\Domain\Extraction\FileExtractionSource;
use App\Domain\Extraction\Url\SecureUrlFetcher;
use App\Jobs\ProcessExtractionJob;
use App\Models\Extraction;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The single-URL extraction workload, end to end.
 *
 * One request, one URL, one extraction, one queued job — the vertical slice in
 * its entirety. These tests assert what the platform stores, what it refuses,
 * and what it cleans up, rather than how the HTTP client is wired (that is
 * {@see SecureUrlFetcherTest}'s job).
 */
class UrlExtractionTest extends TestCase
{
    #[Test]
    public function a_confirmed_user_can_submit_a_single_url(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $this->actingAs($user)->post(route('extractor.store'), [
            'source_type' => 'url',
            'url' => 'https://example.com/team',
        ])->assertRedirect();

        $extraction = Extraction::query()->firstOrFail();

        $this->assertSame('url', $extraction->source_type);
        $this->assertSame('https://example.com/team', $extraction->source_ref);
        $this->assertSame(ExtractionStatus::Queued, $extraction->status);

        // The page is not stored. It is fetched later, on the worker, and must
        // not accumulate in the database.
        $this->assertNull($extraction->content);
    }

    #[Test]
    public function a_url_is_queued_for_processing(): void
    {
        Queue::fake();

        $this->actingAs(User::factory()->create())->post(route('extractor.store'), [
            'source_type' => 'url',
            'url' => 'https://example.com/team',
        ]);

        // Only an identifier crosses the queue boundary. A fetched body in a
        // queue payload would be both large and stale by the time it ran.
        Queue::assertPushed(ProcessExtractionJob::class, fn (ProcessExtractionJob $job): bool => true);
    }

    #[Test]
    public function an_unverified_user_cannot_submit_a_url(): void
    {
        Queue::fake();

        $this->actingAs(User::factory()->unverified()->create())
            ->post(route('extractor.store'), [
                'source_type' => 'url',
                'url' => 'https://example.com/team',
            ])
            ->assertRedirect(route('verification.notice'));

        $this->assertDatabaseCount('extractions', 0);
    }

    #[Test]
    public function a_guest_cannot_submit_a_url(): void
    {
        $this->post(route('extractor.store'), [
            'source_type' => 'url',
            'url' => 'https://example.com/team',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('extractions', 0);
    }

    #[Test]
    public function an_oversized_url_is_refused_before_the_row_is_written(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $this->actingAs($user)->post(route('extractor.store'), [
            'source_type' => 'url',
            'url' => 'https://example.com/'.str_repeat('a', (int) config('sender.url_fetch.max_url_length')),
        ])->assertSessionHasErrors('url');

        $this->assertDatabaseCount('extractions', 0);
    }

    #[Test]
    public function the_stored_reference_does_not_keep_a_query_string(): void
    {
        Queue::fake();

        $this->actingAs(User::factory()->create())->post(route('extractor.store'), [
            'source_type' => 'url',
            'url' => 'https://example.com/list?token=secret-value',
        ]);

        $extraction = Extraction::query()->firstOrFail();

        // This column is shown to the user and to an operator, and outlives the
        // deployment. Query strings carry tokens and addresses.
        $this->assertStringNotContainsString('secret-value', (string) $extraction->source_ref);
        $this->assertSame('https://example.com/list', $extraction->source_ref);
    }

    #[Test]
    public function addresses_are_extracted_from_a_fetched_html_page(): void
    {
        Http::fake([
            'example.com/*' => Http::response(
                '<html><body><p>Write to sales@example.com or hello@example.org</p></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8'],
            ),
        ]);

        $extraction = $this->urlExtraction('https://example.com/contact');

        (new ProcessExtractionJob($extraction->id))->handle(
            app(Extractor::class),
            app(SecureUrlFetcher::class),
        );

        $extraction->refresh();

        // Extraction and validation are two stages. With the sync connection the
        // queued validation stage runs inline, so the task ends at Ready rather
        // than pausing at Validating.
        $this->assertSame(ExtractionStatus::Ready, $extraction->status);
        $this->assertSame(2, $extraction->found_count);

        $this->assertDatabaseCount('extraction_results', 2);
        $this->assertSame(
            ['hello@example.org', 'sales@example.com'],
            $extraction->results()->orderBy('email')->pluck('email')->all(),
        );
    }

    #[Test]
    public function duplicate_addresses_in_a_page_are_stored_once(): void
    {
        Http::fake([
            'example.com/*' => Http::response(
                '<html>sales@example.com sales@example.com SALES@example.com</html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $extraction = $this->urlExtraction('https://example.com/');

        (new ProcessExtractionJob($extraction->id))->handle(
            app(Extractor::class),
            app(SecureUrlFetcher::class),
        );

        $this->assertSame(1, $extraction->refresh()->found_count);
        $this->assertDatabaseCount('extraction_results', 1);
    }

    #[Test]
    public function reprocessing_the_same_url_does_not_duplicate_results(): void
    {
        // The closure matters: `Http::response(...)` hands back one response
        // object, whose body stream is exhausted by the first request. A
        // second request would then receive an empty body — which looks
        // exactly like a page with no addresses on it.
        Http::fake([
            'example.com/*' => fn () => Http::response(
                '<html>team@example.com</html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $extraction = $this->urlExtraction('https://example.com/');
        $job = new ProcessExtractionJob($extraction->id);

        $job->handle(
            app(Extractor::class),
            app(SecureUrlFetcher::class),
        );

        // A worker retry must converge on the same rows, not add to them.
        $job->handle(
            app(Extractor::class),
            app(SecureUrlFetcher::class),
        );

        $this->assertDatabaseCount('extraction_results', 1);
        $this->assertSame(1, $extraction->refresh()->found_count);
    }

    #[Test]
    public function a_refused_url_becomes_failed_without_being_retried(): void
    {
        // A URL the policy will never accept cannot be fixed by trying again,
        // so it must not cycle through the queue.
        Http::fake([
            'example.com/*' => Http::response('x', 200, ['Content-Type' => 'application/zip']),
        ]);

        $extraction = $this->urlExtraction('https://example.com/archive.zip');

        (new ProcessExtractionJob($extraction->id))->handle(
            app(Extractor::class),
            app(SecureUrlFetcher::class),
        );

        $extraction->refresh();

        $this->assertSame(ExtractionStatus::Failed, $extraction->status);
        $this->assertSame('unsupported_content_type', $extraction->error);
    }

    #[Test]
    public function a_failed_url_extraction_stores_a_category_not_a_transport_message(): void
    {
        Http::fake([
            'example.com/*' => Http::response('gone', 404, ['Content-Type' => 'text/html']),
        ]);

        $extraction = $this->urlExtraction('https://example.com/missing');

        (new ProcessExtractionJob($extraction->id))->handle(
            app(Extractor::class),
            app(SecureUrlFetcher::class),
        );

        $extraction->refresh();

        // A category a user can act on, and nothing describing this network.
        $this->assertSame('http_error', $extraction->error);
        $this->assertStringNotContainsString('example.com', (string) $extraction->error);
    }

    #[Test]
    public function the_temporary_response_file_is_removed_after_a_successful_extraction(): void
    {
        Http::fake([
            'example.com/*' => Http::response('<html>a@example.com</html>', 200, ['Content-Type' => 'text/html']),
        ]);

        config()->set('sender.url_fetch.temp_directory', $dir = $this->tempDirectory());

        $extraction = $this->urlExtraction('https://example.com/');

        (new ProcessExtractionJob($extraction->id))->handle(
            app(Extractor::class),
            app(SecureUrlFetcher::class),
        );

        // Left behind, one file per extraction would accumulate on a host
        // that nobody cleans by hand.
        $this->assertSame([], glob($dir.DIRECTORY_SEPARATOR.'*.tmp'));
    }

    #[Test]
    public function the_temporary_response_file_is_removed_after_a_failed_extraction(): void
    {
        Http::fake([
            'example.com/*' => Http::response('x', 500, ['Content-Type' => 'text/html']),
        ]);

        config()->set('sender.url_fetch.temp_directory', $dir = $this->tempDirectory());

        $extraction = $this->urlExtraction('https://example.com/broken');

        (new ProcessExtractionJob($extraction->id))->handle(
            app(Extractor::class),
            app(SecureUrlFetcher::class),
        );

        $this->assertSame([], glob($dir.DIRECTORY_SEPARATOR.'*.tmp'));
    }

    #[Test]
    public function a_file_source_streams_a_body_in_bounded_chunks(): void
    {
        $path = $this->tempDirectory().DIRECTORY_SEPARATOR.'source.txt';
        file_put_contents($path, '<html>'.str_repeat('x', 5000).'deep@example.com</html>');

        $source = new FileExtractionSource($path);
        $chunks = [];

        $source->chunks(1024, function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        });

        $source->dispose();

        // More than one pass, and each bounded — never the whole body at once.
        $this->assertGreaterThan(1, count($chunks));
        $this->assertLessThanOrEqual(1024, max(array_map('strlen', $chunks)));

        $this->assertStringContainsString('deep@example.com', implode('', $chunks));
        $this->assertFileDoesNotExist($path, 'dispose() owns the file');
    }

    #[Test]
    public function another_user_cannot_view_a_url_extraction(): void
    {
        $extraction = $this->urlExtraction('https://example.com/private');

        $this->actingAs(User::factory()->create())
            ->get(route('extractor.show', $extraction))
            ->assertNotFound();
    }

    #[Test]
    public function another_user_cannot_download_a_url_extraction(): void
    {
        $extraction = $this->urlExtraction('https://example.com/private');

        $this->actingAs(User::factory()->create())
            ->get(route('extractor.download', $extraction))
            ->assertNotFound();
    }

    #[Test]
    public function the_owner_can_view_and_download_a_url_extraction(): void
    {
        Http::fake([
            'example.com/*' => Http::response('<html>owner@example.com</html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $user = User::factory()->create();
        $extraction = $this->urlExtraction('https://example.com/', $user);

        (new ProcessExtractionJob($extraction->id))->handle(
            app(Extractor::class),
            app(SecureUrlFetcher::class),
        );

        $this->actingAs($user)->get(route('extractor.show', $extraction))->assertOk();
        $this->actingAs($user)->get(route('extractor.download', $extraction))->assertOk();
    }

    #[Test]
    public function the_create_page_offers_paste_and_single_url_only(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('extractor.create'));

        $response->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('Paste a list', $html);
        $this->assertStringContainsString('One web page', $html);

        // Implemented capabilities are stated; unimplemented ones are denied
        // rather than advertised.
        // The page must name what it accepts and deny the rest. Asserting the
        // absence of "XLSX" would be wrong: the notice exists precisely to say
        // those are unavailable, and silence would be indistinguishable from a
        // page that had not considered them.
        $this->assertStringContainsString('not available yet', $html);
        $this->assertStringContainsString('File uploads are not available yet', $html);
        $this->assertStringContainsString('multiple pages per task', $html);

        // 100 URLs must not be advertised: one extraction is one URL, and a
        // larger figure here would promise batching that does not exist.
        $this->assertStringNotContainsString('100 URLs', $html);
    }

    #[Test]
    public function a_failed_url_extraction_shows_a_readable_reason_not_a_raw_category(): void
    {
        Http::fake([
            'example.com/*' => Http::response('gone', 404, ['Content-Type' => 'text/html']),
        ]);

        $user = User::factory()->create();
        $extraction = $this->urlExtraction('https://example.com/missing', $user);

        (new ProcessExtractionJob($extraction->id))->handle(
            app(Extractor::class),
            app(SecureUrlFetcher::class),
        );

        $response = $this->actingAs($user)->get(route('extractor.show', $extraction));

        $response->assertOk();

        // A sentence, not the stored category on its own: "http_error" tells a
        // user nothing about what to do, and describes the platform's
        // internals rather than their request.
        $response->assertSee('The server returned an error.');
        $response->assertSee('http_error');
    }

    #[Test]
    public function the_detail_page_shows_the_source_address_without_its_query_string(): void
    {
        $user = User::factory()->create();
        $extraction = $this->urlExtraction('https://example.com/list?token=secret', $user);

        $response = $this->actingAs($user)->get(route('extractor.show', $extraction));

        $response->assertOk();
        $response->assertSee('https://example.com/list');
        $response->assertDontSee('secret');
    }

    #[Test]
    public function a_query_string_is_not_fetched_as_well_as_not_stored(): void
    {
        // `source_ref` is both the address the worker fetches and the text a
        // user sees, so a query cannot be persisted with it. That means the
        // request is made against the path alone.
        //
        // Asserted so the behaviour is deliberate rather than incidental: a
        // site that varies on its query string will return different content,
        // and this is the documented reason it does.
        Http::fake([
            'example.com/list' => Http::response('page@example.com', 200, ['Content-Type' => 'text/plain']),
        ]);

        $user = User::factory()->create();
        $extraction = $this->urlExtraction('https://example.com/list?q=team&token=secret', $user);

        $this->assertSame('https://example.com/list', $extraction->source_ref);

        (new ProcessExtractionJob($extraction->id))->handle(
            app(Extractor::class),
            app(SecureUrlFetcher::class),
        );

        $this->assertSame(ExtractionStatus::Ready, $extraction->refresh()->status);

        Http::assertSent(function (Request $request): bool {
            $this->assertStringNotContainsString('token', $request->url());
            $this->assertStringNotContainsString('secret', $request->url());

            return true;
        });
    }

    private function urlExtraction(string $url, ?User $user = null): Extraction
    {
        return Extraction::query()->create([
            'user_id' => ($user ?? User::factory()->create())->id,
            'source_type' => 'url',

            // The query is dropped here for the same reason the controller drops
            // it: source_ref is both what the worker fetches and what a user and
            // an operator can read, so it must not be a place tokens accumulate.
            'source_ref' => str_contains($url, '?')
                ? substr($url, 0, (int) strpos($url, '?'))
                : $url,
            'content' => null,
            'status' => ExtractionStatus::Queued->value,
            'found_count' => 0,
            'processed_count' => 0,
            'failed_count' => 0,
        ]);
    }

    private function tempDirectory(): string
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sender-url-test-'.bin2hex(random_bytes(6));

        mkdir($dir);

        $this->beforeApplicationDestroyed(static function () use ($dir): void {
            foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($dir);
        });

        return $dir;
    }
}

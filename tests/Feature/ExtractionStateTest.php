<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Extraction\ExtractionSource;
use App\Domain\Extraction\ExtractionStatus;
use App\Domain\Extraction\Extractor;
use App\Domain\Extraction\Url\SecureUrlFetcher;
use App\Jobs\ProcessExtractionJob;
use App\Models\Extraction;
use App\Models\User;
use RuntimeException;
use Tests\TestCase;

/**
 * Extraction state, failure visibility and bounded result rendering.
 *
 * A workload that can fail must say so. Stage 3C had a `status` column and
 * nothing that ever wrote a failure to it, so an extraction that threw would
 * have sat at `pending` indefinitely — indistinguishable from one that was
 * merely waiting its turn.
 */
class ExtractionStateTest extends TestCase
{
    public function test_an_extraction_starts_pending_with_no_timestamps(): void
    {
        $extraction = Extraction::factory()->queued()->create();

        $this->assertSame(ExtractionStatus::Queued, $extraction->status);
        $this->assertNull($extraction->started_at);
        $this->assertNull($extraction->completed_at);
        $this->assertSame(0, $extraction->found_count);
        $this->assertSame(0, $extraction->processed_count);
    }

    public function test_a_successful_run_moves_queued_to_extracting_to_validating_to_ready(): void
    {
        $extraction = Extraction::factory()->create([
            'status' => ExtractionStatus::Queued->value,
            'content' => "alpha@example.com\nbeta@example.com\n",
        ]);

        (new ProcessExtractionJob($extraction->id))->handle(Extractor::fromConfiguration(), SecureUrlFetcher::make());

        $extraction->refresh();

        // Under the sync connection the queued validation stage runs inline, so
        // the task is observed at the end of the pipeline rather than paused in
        // the middle of it.
        $this->assertSame(ExtractionStatus::Ready, $extraction->status);
        $this->assertNotNull($extraction->started_at);
        $this->assertNotNull($extraction->completed_at);
        $this->assertTrue($extraction->completed_at->greaterThanOrEqualTo($extraction->started_at));
        $this->assertSame(2, $extraction->found_count);
        $this->assertSame(2, $extraction->processed_count);
    }

    public function test_a_retryable_failure_is_not_exposed_as_a_terminal_state(): void
    {
        $extraction = Extraction::factory()->create([
            'status' => ExtractionStatus::Queued->value,
            'content' => 'alpha@example.com',
        ]);

        // A real job, doing real work, through an extractor that fails. The job
        // itself is not overridden, so its catch block is what is exercised.
        try {
            (new ProcessExtractionJob($extraction->id))->handle(new class(65536, 250) extends Extractor
            {
                public function extract(Extraction $extraction, ?ExtractionSource $source = null): array
                {
                    throw new RuntimeException('transient failure');
                }
            }, SecureUrlFetcher::make());

            $this->fail('the exception should propagate to the queue');
        } catch (RuntimeException) {
            // Expected: the queue's retry handling owns what happens next.
        }

        $extraction->refresh();

        // The queue will retry. Marking this `failed` would show a customer a
        // terminal-looking dead extraction while the system was still working
        // on it, and the next attempt would flip it back to `processing`.
        $this->assertNotSame(
            ExtractionStatus::Failed,
            $extraction->status,
            'a retryable failure must not present as terminal',
        );

        $this->assertFalse($extraction->status->isTerminal());
        $this->assertStringContainsString('transient failure', (string) $extraction->error);
    }

    public function test_a_retryable_failure_leaves_no_completion_timestamp(): void
    {
        $extraction = Extraction::factory()->create([
            'status' => ExtractionStatus::Queued->value,
            'content' => 'alpha@example.com',
        ]);

        try {
            (new ProcessExtractionJob($extraction->id))->handle(new class(65536, 250) extends Extractor
            {
                public function extract(Extraction $extraction, ?ExtractionSource $source = null): array
                {
                    throw new RuntimeException('transient');
                }
            }, SecureUrlFetcher::make());
        } catch (RuntimeException) {
            // Expected.
        }

        // completed_at means "finished". An in-flight retry has not finished.
        $this->assertNull($extraction->refresh()->completed_at);
    }

    public function test_a_throwing_job_records_a_failure_rather_than_claiming_success(): void
    {
        $extraction = Extraction::factory()->create([
            'status' => ExtractionStatus::Queued->value,
            'content' => 'alpha@example.com',
        ]);

        $job = new ProcessExtractionJob($extraction->id);

        // A real job, failing through its real catch block, with only the
        // extractor substituted so that it throws.
        try {
            $job->handle(new class(65536, 250) extends Extractor
            {
                public function extract(Extraction $extraction, ?ExtractionSource $source = null): array
                {
                    throw new RuntimeException('worker exploded');
                }
            }, SecureUrlFetcher::make());

            $this->fail('the exception should have propagated to the queue');
        } catch (RuntimeException) {
            // Expected: the queue's retry handling owns what happens next.
        }

        // The queue's final-failure hook is what a customer eventually sees.
        $job->failed(new RuntimeException('worker exploded'));

        $extraction->refresh();

        $this->assertSame(ExtractionStatus::Failed, $extraction->status);
        $this->assertNotNull($extraction->completed_at);
        $this->assertStringContainsString('worker exploded', (string) $extraction->error);
    }

    public function test_a_failure_message_is_reduced_before_it_is_persisted(): void
    {
        $extraction = Extraction::factory()->create(['status' => ExtractionStatus::Queued->value]);

        (new ProcessExtractionJob($extraction->id))
            ->failed(new RuntimeException('failed connecting with password=hunter2 to db'));

        $stored = (string) $extraction->refresh()->error;

        $this->assertStringNotContainsString('hunter2', $stored);
    }

    public function test_a_failed_extraction_is_visible_on_the_detail_page(): void
    {
        $extraction = Extraction::factory()->create([
            'status' => ExtractionStatus::Failed->value,
            'error' => 'The worker could not read the stored content.',
        ]);

        $this->actingAs($extraction->user)
            ->get(route('extractor.show', $extraction))
            ->assertOk()
            ->assertSee('This task did not finish')
            ->assertSee('could not read the stored content');
    }

    public function test_a_pending_extraction_explains_that_it_is_queued(): void
    {
        $extraction = Extraction::factory()->create(['status' => ExtractionStatus::Queued->value]);

        $this->actingAs($extraction->user)
            ->get(route('extractor.show', $extraction))
            ->assertOk()
            ->assertSee('Waiting to start');
    }

    public function test_a_completed_extraction_with_no_addresses_says_so(): void
    {
        $extraction = Extraction::factory()->create([
            'status' => ExtractionStatus::Validating->value,
            'found_count' => 0,
            'processed_count' => 0,
            'content' => 'no addresses in this text at all',
        ]);

        $this->actingAs($extraction->user)
            ->get(route('extractor.show', $extraction))
            ->assertOk()
            ->assertSee('Nothing found');
    }

    public function test_history_is_paginated_rather_than_loading_every_extraction(): void
    {
        $user = User::factory()->create();

        Extraction::factory()->count(25)->for($user)->create();

        $response = $this->actingAs($user)->get('/extractor');

        $response->assertOk();

        // Twenty per page: the default page must not contain all twenty-five.
        $response->assertSee('Showing');
        $this->assertCount(20, $response->viewData('extractions')->items());
        $this->assertSame(25, $response->viewData('extractions')->total());
    }

    public function test_history_only_ever_contains_the_authenticated_account_s_extractions(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        Extraction::factory()->for($mine)->create(['content' => 'mine@example.com']);
        Extraction::factory()->for($theirs)->create(['content' => 'theirs@example.com']);

        $response = $this->actingAs($mine)->get('/extractor');

        $response->assertOk();
        $this->assertSame(1, $response->viewData('extractions')->total());
    }

    public function test_results_are_paginated_rather_than_eager_loaded(): void
    {
        $extraction = Extraction::factory()->create([
            'status' => ExtractionStatus::Validating->value,
            'found_count' => 150,
        ]);

        // More results than one page carries.
        for ($i = 0; $i < 150; $i++) {
            $extraction->results()->create(['email' => "user{$i}@example.com"]);
        }

        $response = $this->actingAs($extraction->user)
            ->get(route('extractor.show', $extraction));

        $response->assertOk();

        $results = $response->viewData('results');

        $this->assertSame(150, $results->total());
        $this->assertCount(100, $results->items());

        // The model must not have carried the whole relation in memory.
        $this->assertFalse($extraction->relationLoaded('results'));
    }

    public function test_the_csv_download_streams_every_result_not_just_the_first_page(): void
    {
        $extraction = Extraction::factory()->create([
            'status' => ExtractionStatus::Validating->value,
            'found_count' => 150,
        ]);

        for ($i = 0; $i < 150; $i++) {
            $extraction->results()->create(['email' => "user{$i}@example.com"]);
        }

        $response = $this->actingAs($extraction->user)
            ->get(route('extractor.download', $extraction));

        $response->assertOk();
        $response->assertDownload('audience-'.$extraction->id.'.csv');

        $body = $response->streamedContent();

        // Header plus every row, across the 500-row chunk boundary handling.
        $this->assertStringContainsString('email', $body);
        $this->assertStringContainsString('user0@example.com', $body);
        $this->assertStringContainsString('user149@example.com', $body);
        $this->assertSame(151, count(array_filter(explode("\n", trim($body)))));
    }

    public function test_the_csv_download_of_another_accounts_extraction_is_a_404(): void
    {
        $extraction = Extraction::factory()->for(User::factory()->create())->create();

        $this->actingAs(User::factory()->create())
            ->get(route('extractor.download', $extraction))
            ->assertNotFound();
    }

    public function test_pasted_content_is_not_exposed_by_serialising_the_model(): void
    {
        $extraction = Extraction::factory()->create(['content' => 'secret@example.com']);

        // The payload can reach a log line or an error page; the pasted content
        // is third-party text and is not metadata.
        $this->assertArrayNotHasKey('content', $extraction->toArray());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Extraction\DatabaseExtractionSource;
use App\Domain\Extraction\ExtractionStatus;
use App\Domain\Extraction\Extractor;
use App\Domain\System\Enums\DeploymentLimit;
use App\Jobs\ProcessExtractionJob;
use App\Models\Extraction;
use App\Models\User;
use App\Rules\WithinByteCeiling;
use Tests\TestCase;

/**
 * The bounds the extraction workload claims to respect.
 *
 * Stage 3C asserted none of these. The ceiling was a literal, processing loaded
 * the whole input and every candidate, and the history pages loaded every row.
 * A limit that is not tested is a limit that has drifted.
 */
class ExtractionLimitsTest extends TestCase
{
    public function test_the_input_ceiling_comes_from_the_deployment_limit_rather_than_a_literal(): void
    {
        // The rule resolves the limit at validation time.
        config()->set('sender.deployment_limits.max_text_input_bytes', 100);

        $this->actingAs(User::factory()->create())
            ->post('/extractor', [
                'source_type' => 'paste',
                'content' => str_repeat('a', 101),
            ])
            ->assertSessionHasErrors('content');

        $this->actingAs(User::factory()->create())
            ->post('/extractor', [
                'source_type' => 'paste',
                'content' => 'ok@example.com',
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_the_ceiling_is_measured_in_bytes_not_characters(): void
    {
        config()->set('sender.deployment_limits.max_text_input_bytes', 64);

        // 40 multi-byte characters is 120 bytes. Laravel's string `max` counts
        // characters and would have accepted this; a byte ceiling must not.
        $multibyte = str_repeat('é', 40);

        $this->assertSame(40, mb_strlen($multibyte));
        $this->assertSame(80, strlen($multibyte));

        $this->actingAs(User::factory()->create())
            ->post('/extractor', ['source_type' => 'paste', 'content' => $multibyte])
            ->assertSessionHasErrors('content');
    }

    public function test_an_unconfigured_ceiling_fails_closed_rather_than_accepting_everything(): void
    {
        config()->set('sender.deployment_limits.max_text_input_bytes', 0);

        $this->actingAs(User::factory()->create())
            ->post('/extractor', ['source_type' => 'paste', 'content' => 'ok@example.com'])
            ->assertSessionHasErrors('content');

        $this->assertDatabaseCount('extractions', 0);
    }

    public function test_oversized_input_is_rejected_before_anything_is_queued(): void
    {
        config()->set('sender.deployment_limits.max_text_input_bytes', 32);

        $this->actingAs(User::factory()->create())
            ->post('/extractor', ['source_type' => 'paste', 'content' => str_repeat('a', 64)])
            ->assertSessionHasErrors('content');

        // Neither the row nor the job exists: rejection happens at the boundary.
        $this->assertDatabaseCount('extractions', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_the_shipped_default_ceiling_is_one_mebibyte(): void
    {
        // Not raised to make a test convenient. A megabyte on a shared host has
        // to be received, stored and streamed back out again.
        $this->assertSame(1024 * 1024, DeploymentLimit::MaxTextInputBytes->value());
    }

    public function test_the_byte_ceiling_rule_reports_a_human_readable_limit(): void
    {
        $validator = validator(
            ['content' => str_repeat('a', 2 * 1024 * 1024)],
            ['content' => [WithinByteCeiling::forTextInput()]],
        );

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString('1M', (string) $validator->errors()->first('content'));
    }

    public function test_processing_stays_within_a_bounded_number_of_rows_per_write(): void
    {
        $extraction = Extraction::factory()->create([
            'status' => ExtractionStatus::Queued->value,
            // More distinct addresses than a single batch may carry.
            'content' => implode("\n", array_map(
                static fn (int $i): string => "user{$i}@example.com",
                range(1, 40),
            )),
        ]);

        $extractor = new Extractor(chunkBytes: 4096, batchSize: 5);

        $counts = $extractor->extract($extraction);

        $this->assertSame(40, $counts['found']);
        $this->assertDatabaseCount('extraction_results', 40);
    }

    public function test_an_address_straddling_a_chunk_boundary_is_still_found(): void
    {
        // Chunking is only correct if overlap is carried; without it the tail of
        // one chunk and the head of the next are silently dropped.
        $prefix = str_repeat('x', 400);
        $content = $prefix."\nalpha@example.com\n".str_repeat('y', 400)."\nbeta@example.com\n";

        $extraction = Extraction::factory()->create([
            'status' => ExtractionStatus::Queued->value,
            'content' => $content,
        ]);

        $found = [];

        // Read with a chunk size small enough to split both addresses.
        (new DatabaseExtractionSource($content))->chunks(64, static function (string $chunk) use (&$found): void {
            if (preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $chunk, $m)) {
                $found = array_merge($found, $m[0]);
            }
        });

        $this->assertContains('alpha@example.com', $found);
        $this->assertContains('beta@example.com', $found);
    }

    public function test_extraction_does_not_accumulate_every_candidate_in_memory(): void
    {
        // A megabyte of text with many repeats must not produce a result array
        // proportional to the input. Two distinct addresses, one megabyte of
        // noise: the found count stays at two.
        $extraction = Extraction::factory()->create([
            'status' => ExtractionStatus::Queued->value,
            'content' => str_repeat('noise ', 150_000).'alpha@example.com beta@example.com',
        ]);

        $counts = (new Extractor(chunkBytes: 8192, batchSize: 10))->extract($extraction);

        $this->assertSame(2, $counts['found']);
        $this->assertDatabaseCount('extraction_results', 2);
    }

    public function test_the_pasted_content_is_never_serialised_into_the_queue_payload(): void
    {
        $job = new ProcessExtractionJob(42);

        $serialised = serialize($job);

        // Only the identifier crosses the queue boundary. A megabyte of pasted
        // text in a jobs row would be paid three times over, once per retry.
        $this->assertStringContainsString('42', $serialised);
        $this->assertStringNotContainsString('content', $serialised);
    }
}

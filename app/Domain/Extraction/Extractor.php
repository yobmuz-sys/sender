<?php

declare(strict_types=1);

namespace App\Domain\Extraction;

use App\Domain\System\Enums\DeploymentLimit;
use App\Models\Extraction;

/**
 * Extracts addresses from a stored extraction, in bounded memory.
 *
 * The shape of the loop is the whole point:
 *
 *     read one chunk
 *       -> find candidates in that chunk only
 *       -> normalise, validate, drop repeats already seen
 *       -> persist the batch
 *       -> forget the batch
 *       -> next chunk
 *
 * Nothing accumulates across chunks. `found` is a set of addresses, not a set
 * of positions, so it grows with the number of *distinct* results rather than
 * with the size of the input — and it is bounded by the unique constraint on
 * the table, which is the final authority anyway. `processed` is a counter.
 *
 * Idempotency is enforced by the database: writes are an upsert against
 * unique(extraction_id, email). Re-running the job over the same content
 * converges on the same rows instead of duplicating them, which is what makes a
 * worker retry safe.
 *
 * Not final: the job takes one as a parameter, so a test can substitute one
 * that fails and observe how the job records a retryable failure. The
 * alternative — forcing a real extraction to throw — would test the failure
 * path only by breaking something unrelated.
 */
class Extractor
{
    /**
     * Matches address-shaped text.
     *
     * Deliberately permissive about what surrounds an address, because the
     * input is arbitrary pasted text. It is not a validator — every candidate
     * is lower-cased and run through FILTER_VALIDATE_EMAIL before anything is
     * written, so a permissive match cannot put a malformed value in the table.
     */
    private const PATTERN = '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/';

    public function __construct(
        private readonly int $chunkBytes,
        private readonly int $batchSize,
    ) {}

    public static function fromConfiguration(): self
    {
        return new self(
            chunkBytes: (int) config('sender.extraction.chunk_bytes', 64 * 1024),
            // Falls back to the shared deployment limit rather than a second
            // number that could disagree with it.
            batchSize: (int) (config('sender.extraction.batch_size') ?: DeploymentLimit::MaxJobBatchSize->value()),
        );
    }

    /**
     * Process an extraction, writing results as it goes.
     *
     * The source is injected rather than assumed. Pasted text arrives from the
     * database and a URL arrives as a temporary file, and the extraction
     * algorithm is the same in both cases — it must not know which, or the
     * fetch would end up entangled with the pattern matching.
     *
     * @param  ExtractionSource|null  $source  Defaults to the stored content.
     * @return array{processed: int, found: int}
     */
    public function extract(Extraction $extraction, ?ExtractionSource $source = null): array
    {
        $source ??= new DatabaseExtractionSource((string) ($extraction->content ?? ''));

        $batch = [];
        $found = [];
        $processed = 0;

        $flush = function () use (&$batch, $extraction): void {
            if ($batch === []) {
                return;
            }

            // The unique constraint on (extraction_id, email) is what makes a
            // retry safe. Nothing here checks whether a row already exists:
            // doing so in PHP would leave a window between the check and the
            // write in which a concurrent worker inserts the same address.
            $extraction->results()->upsert(
                $batch,
                ['extraction_id', 'email'],
                ['email'],
            );

            $batch = [];
        };

        $source->chunks($this->chunkBytes, function (string $chunk) use (&$batch, &$found, &$processed, $extraction, $flush): void {
            if (! preg_match_all(self::PATTERN, $chunk, $matches)) {
                return;
            }

            foreach ($matches[0] as $candidate) {
                $processed++;

                $email = $this->normalise((string) $candidate);

                if ($email === null || isset($found[$email])) {
                    continue;
                }

                $found[$email] = true;
                $batch[] = ['extraction_id' => $extraction->id, 'email' => $email];

                if (count($batch) >= $this->batchSize) {
                    $flush();
                }
            }
        });

        $flush();

        return ['processed' => $processed, 'found' => count($found)];
    }

    /**
     * A candidate reduced to a canonical address, or null if it is not one.
     */
    private function normalise(string $candidate): ?string
    {
        $email = strtolower(trim($candidate));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }
}

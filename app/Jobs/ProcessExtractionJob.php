<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Extraction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessExtractionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Finite execution.
     *
     * Kept below the connection's `retry_after`, so a worker that overruns is
     * killed and retried rather than being handed to a second worker while the
     * first is still running, which would process the same extraction twice.
     */
    public int $timeout = 240;

    public int $maxExceptions = 3;

    public function __construct(public int $extractionId) {}

    public function handle(): void
    {
        $extraction = Extraction::query()->findOrFail($this->extractionId);

        $rows = [];
        $seen = [];

        foreach ($this->extractEmails($extraction) as $email) {
            $clean = strtolower(trim((string) $email));

            if ($clean === '' || ! filter_var($clean, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            if (isset($seen[$clean])) {
                continue;
            }

            $seen[$clean] = true;
            $rows[] = [
                'extraction_id' => $extraction->id,
                'email' => $clean,
            ];
        }

        if ($rows !== []) {
            $extraction->results()->upsert($rows, ['extraction_id', 'email'], ['email']);
        }

        $extraction->forceFill([
            'status' => 'completed',
            'found_count' => $extraction->results()->count(),
        ])->save();
    }

    private function extractEmails(Extraction $extraction): array
    {
        $content = (string) ($extraction->content ?? '');

        if ($content === '') {
            return [];
        }

        preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $content, $matches);

        return $matches[0] ?? [];
    }
}

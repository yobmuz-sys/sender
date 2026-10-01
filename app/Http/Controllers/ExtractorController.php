<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Extraction\ExtractionStatus;
use App\Jobs\ProcessExtractionJob;
use App\Models\Extraction;
use App\Models\ExtractionResult;
use App\Rules\WithinByteCeiling;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The pasted-text extraction workload.
 *
 * Every query is scoped to the authenticated account and a record belonging to
 * somebody else is answered with 404 rather than 403, because a 403 confirms
 * the record exists and lets one account enumerate another's extraction
 * identifiers.
 *
 * Results are paginated rather than loaded. An extraction of a megabyte of text
 * can hold a great many addresses, and rendering all of them into one response
 * is exactly the unbounded behaviour the rest of this stage removes.
 */
class ExtractorController extends Controller
{
    /**
     * Rows of extraction history per page.
     */
    private const HISTORY_PER_PAGE = 20;

    /**
     * Rows of results per page.
     *
     * Results are what a customer actually reads, so this is larger than the
     * history page; the CSV download exists for the whole set.
     */
    private const RESULTS_PER_PAGE = 100;

    public function index(Request $request): View
    {
        $extractions = Extraction::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate(self::HISTORY_PER_PAGE);

        return view('extractor.index', [
            'extractions' => $extractions,
        ]);
    }

    public function create(): View
    {
        return view('extractor.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // Pasted content and a single URL. File upload and multiple URLs are
            // not implemented, so they are not accepted: a submission the
            // platform cannot act on is a claim, not a placeholder.
            'source_type' => ['required', 'string', 'in:paste,url'],

            // A byte ceiling from the deployment limits, measured with strlen
            // rather than counted in characters. Rejects before dispatch, so
            // oversized input never reaches the queue or the database.
            'content' => ['nullable', 'string', WithinByteCeiling::forTextInput()],

            // Checked for presence rather than shape: the shape is the fetcher's
            // policy, and duplicating it here would create a second set of rules
            // to keep in step. What validation contributes is refusing an
            // implausible length before the row is written.
            'url' => ['nullable', 'string', 'max:'.(int) config('sender.url_fetch.max_url_length', 2048)],
        ]);

        $isUrl = $validated['source_type'] === 'url';

        $extraction = Extraction::query()->create([
            'user_id' => $request->user()->id,
            'source_type' => $validated['source_type'],

            // Pasted text is persisted so the worker can read it back; a URL is
            // not, because the page it points at is fetched later and must not
            // be stored.
            'content' => $isUrl ? null : (string) ($validated['content'] ?? ''),

            // The reference is stored without its query string, because that is
            // what an operator and a customer both see and queries routinely
            // carry tokens and addresses.
            'source_ref' => $isUrl
                ? $this->safeReference((string) ($validated['url'] ?? ''))
                : null,

            'status' => ExtractionStatus::Pending->value,
            'found_count' => 0,
            'processed_count' => 0,
            'failed_count' => 0,
        ]);

        // Only the identifier crosses the queue boundary; the worker reads the
        // content back. A large paste therefore does not inflate the queued
        // payload, and no page body ever reaches the queue either.
        ProcessExtractionJob::dispatch($extraction->id);

        return redirect()->route('extractor.show', $extraction);
    }

    /**
     * The URL as it will be shown and stored, without its query string.
     */
    private function safeReference(string $url): string
    {
        return str_contains($url, '?')
            ? substr($url, 0, (int) strpos($url, '?'))
            : $url;
    }

    public function history(Request $request): View
    {
        return $this->index($request);
    }

    public function show(Request $request, mixed $extraction = null): View
    {
        if (! is_numeric((string) $extraction)) {
            abort(404);
        }

        $record = Extraction::query()->find((int) $extraction);

        if ($record === null || $record->user_id !== $request->user()->id) {
            abort(404);
        }

        return view('extractor.show', [
            'extraction' => $record,
            // Paginated rather than eager-loaded. `load('results')` here would
            // hold every address for every extraction a customer opens.
            'results' => $record->results()
                ->orderBy('id')
                ->paginate(self::RESULTS_PER_PAGE),
        ]);
    }

    /**
     * Stream every result for one extraction as CSV.
     *
     * Streamed through the response rather than collected, so downloading a
     * large extraction does not build the whole set in memory first.
     */
    public function download(Extraction $extraction): StreamedResponse
    {
        abort_if($extraction->user_id !== auth()->id(), 404);

        return response()->streamDownload(function () use ($extraction): void {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, ['email']);

            // Chunked so the download never materialises the full result set.
            $extraction->results()
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($handle): void {
                    foreach ($rows as $row) {
                        /** @var ExtractionResult $row */
                        fputcsv($handle, [$row->email]);
                    }
                });

            fclose($handle);
        }, 'extraction-'.$extraction->id.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}

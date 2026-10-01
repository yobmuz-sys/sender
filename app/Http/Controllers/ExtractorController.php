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
            // Only pasted content is implemented. 'url' and 'file' are not
            // accepted, because accepting them would store a request the
            // platform cannot act on and then report it as an extraction.
            'source_type' => ['required', 'string', 'in:paste'],

            // A byte ceiling from the deployment limits, measured with strlen
            // rather than counted in characters. Rejects before dispatch, so
            // oversized input never reaches the queue or the database.
            'content' => ['required', 'string', WithinByteCeiling::forTextInput()],
        ]);

        $extraction = Extraction::query()->create([
            'user_id' => $request->user()->id,
            'source_type' => $validated['source_type'],
            'content' => $validated['content'],
            'status' => ExtractionStatus::Pending->value,
            'found_count' => 0,
            'processed_count' => 0,
            'failed_count' => 0,
        ]);

        // Only the identifier crosses the queue boundary; the worker reads the
        // content back. A large paste therefore does not inflate the queued
        // payload.
        ProcessExtractionJob::dispatch($extraction->id);

        return redirect()->route('extractor.show', $extraction);
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

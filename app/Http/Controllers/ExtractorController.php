<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ProcessExtractionJob;
use App\Models\Extraction;
use App\Models\ExtractionResult;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExtractorController extends Controller
{
    public function index(Request $request): View
    {
        $extractions = Extraction::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

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
            'content' => ['nullable', 'string', 'max:20000'],
        ]);

        $content = (string) ($validated['content'] ?? '');

        if (trim($content) === '') {
            abort(422, 'Paste content is required.');
        }

        $extraction = Extraction::query()->create([
            'user_id' => $request->user()->id,
            'source_type' => $validated['source_type'],
            'content' => $content,
            'status' => 'pending',
            'found_count' => 0,
        ]);

        // Only the identifier crosses the queue boundary. The pasted content is
        // read back from the database by the worker, so a large paste cannot
        // inflate the queued payload.
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

        if ($record === null) {
            abort(404);
        }

        abort_if($record->user_id !== $request->user()->id, 404);

        return view('extractor.show', [
            'extraction' => $record->load('results'),
        ]);
    }

    public function download(Extraction $extraction): StreamedResponse
    {
        // A non-disclosing 404, not a 403: confirming that the record exists
        // would let one account enumerate another's extraction identifiers.
        abort_if($extraction->user_id !== auth()->id(), 404);

        return response()->streamDownload(function () use ($extraction): void {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, ['email']);

            $extraction->results()->orderBy('id')
                ->each(function (ExtractionResult $result) use ($handle): void {
                    fputcsv($handle, [$result->email]);
                });

            fclose($handle);
        }, 'extraction-'.$extraction->id.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}

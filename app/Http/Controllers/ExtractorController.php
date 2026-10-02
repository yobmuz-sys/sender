<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audience\ValidationReport;
use App\Domain\Audience\ValidationStatus;
use App\Domain\Extraction\ExtractionStatus;
use App\Domain\Extraction\PendingTaskQueue;
use App\Domain\Extraction\TaskProgress;
use App\Models\Extraction;
use App\Models\ExtractionResult;
use App\Rules\WithinByteCeiling;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Extraction as a task list: one badge per submission, one report per badge.
 *
 * This is the screen a person with no technical knowledge actually uses, and it
 * was reshaped around one question: *what is happening to my list right now?*
 * Before this stage it was a table of extraction ids and a "found" column, which
 * answered nothing about the addresses and nothing about whether they could be
 * used. A row of integers is not progress.
 *
 * Every query is scoped to the authenticated account and a record belonging to
 * somebody else is answered with 404 rather than 403, because a 403 confirms the
 * record exists and lets one account enumerate another's identifiers.
 *
 * Results are paginated rather than loaded throughout. An extraction of a
 * megabyte of text can hold a great many addresses, and rendering all of them
 * into one response is exactly the unbounded behaviour the rest of this stage
 * removes.
 */
class ExtractorController extends Controller
{
    /**
     * Badges per page.
     */
    private const HISTORY_PER_PAGE = 20;

    /**
     * Results per page.
     *
     * Larger than the badge page because results are what a customer reads
     * closely; the CSV export exists for the whole set.
     */
    private const RESULTS_PER_PAGE = 100;

    /**
     * The tenant's task list, newest first.
     */
    public function index(Request $request, PendingTaskQueue $queue): View
    {
        $user = $request->user();

        // One query. The paginator is asked for once and reused: calling
        // `tasksFor()` twice would run the count and the page query twice and
        // could render two different pages if a task arrived between them.
        $tasks = $queue->tasksFor($user);

        return view('extractor.index', [
            'extractions' => $tasks,
            'progress' => $this->progressMap($tasks->getCollection()),

            // Stated on the page rather than left for the customer to infer from
            // a queue depth. "One task is checked at a time" is the reason their
            // second paste has not started yet, and a reason they cannot see
            // reads as a fault.
            'currentTask' => $queue->current($user->id),
            'waitingCount' => $queue->waitingCount($user->id),
        ]);
    }

    public function create(): View
    {
        return view('extractor.create');
    }

    public function store(Request $request, PendingTaskQueue $queue): RedirectResponse
    {
        $validated = $request->validate([
            // Pasted content and a single URL. File upload and multiple URLs are
            // not implemented, so they are not accepted: a submission the
            // platform cannot act on is a claim, not a placeholder.
            'source_type' => ['required', 'string', 'in:paste,url'],

            // What the badge is called. Optional, because a task that describes
            // its own source is still perfectly usable — but a customer who named
            // forty pastes should not be looking at forty identical rows.
            'name' => ['nullable', 'string', 'max:120'],

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
            'name' => $this->taskName($validated['name'] ?? null, $isUrl),

            'source_type' => $validated['source_type'],

            // Pasted text is persisted so the worker can read it back; a URL is
            // not, because the page it points at is fetched later and must not be
            // stored.
            'content' => $isUrl ? null : (string) ($validated['content'] ?? ''),

            // The reference is stored without its query string, because that is
            // what an operator and a customer both see and queries routinely carry
            // tokens and addresses.
            'source_ref' => $isUrl
                ? $this->safeReference((string) ($validated['url'] ?? ''))
                : null,

            'status' => ExtractionStatus::Queued->value,
            'found_count' => 0,
            'processed_count' => 0,
            'failed_count' => 0,
        ]);

        // Dispatched only if this task is the account's current one. A task
        // submitted while another is being checked stays queued and is dispatched
        // when that one reaches a terminal state — not by polling, not by a lock
        // the worker has to retry.
        $queue->submit($extraction);

        return redirect()->route('extractor.show', $extraction);
    }

    public function history(Request $request, PendingTaskQueue $queue): View
    {
        return $this->index($request, $queue);
    }

    /**
     * One task's report.
     *
     * The report is the whole product for a person who does not know what SMTP
     * is: how many addresses were found, how many were checked, how many fell
     * into each of the four outcomes, and what that leaves. The technical reason
     * for each classification is available but never leads — "5.1.1" is not
     * actionable by anyone without the context to read it, and leading with it
     * is how a validation report becomes a support ticket.
     */
    public function show(Request $request, mixed $extraction = null): View
    {
        $record = $this->owned($request, $extraction);

        $statusFilter = $this->statusFilter($request);

        $results = $record->results()
            ->with('contact')
            ->when(
                $statusFilter !== null,
                fn ($query) => $query->where('validation_status', $statusFilter->value),
            )
            ->orderBy('id')
            ->paginate(self::RESULTS_PER_PAGE);

        return view('extractor.show', [
            'extraction' => $record,
            'progress' => TaskProgress::of($record),
            'report' => ValidationReport::from($record),
            'results' => $results,
            'statusFilter' => $statusFilter,
        ]);
    }

    /**
     * Stream every result for one task as CSV.
     *
     * Streamed through the response rather than collected, so downloading a large
     * extraction does not build the whole set in memory first. The validation
     * columns travel with the address, because a customer exporting their list
     * needs the classification they acted on — an export of bare addresses would
     * throw away the entire point of having checked them.
     */
    public function download(Extraction $extraction): StreamedResponse
    {
        abort_if($extraction->user_id !== auth()->id(), 404);

        return response()->streamDownload(function () use ($extraction): void {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, ['email', 'classification', 'reason']);

            // Chunked so the download never materialises the full result set.
            $extraction->results()
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($handle): void {
                    foreach ($rows as $row) {
                        /** @var ExtractionResult $row */
                        fputcsv($handle, [
                            $row->email,
                            $row->validation_status?->value ?? '',
                            $row->validation_reason?->value ?? '',
                        ]);
                    }
                });

            fclose($handle);
        }, 'audience-'.$extraction->id.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Abandon a task that has not started.
     *
     * Only ever applies to a queued task. Cancelling one mid-flight would mean
     * telling a worker that is actively writing rows to stop, which it cannot
     * observe — so the operation is simply refused rather than half-honoured.
     */
    public function cancel(Request $request, PendingTaskQueue $queue, mixed $extraction = null): RedirectResponse
    {
        $record = $this->owned($request, $extraction);

        if (! $queue->cancel($record)) {
            return back()->with('error', 'This task has already started, so it cannot be cancelled.');
        }

        return back()->with('status', 'Task cancelled.');
    }

    /**
     * The report's classification filter, or null for all of them.
     *
     * Parsed against the enum rather than trusted as a string, so an invented
     * value is a 422 rather than a report quietly showing nothing and looking
     * like an audience with no matches.
     */
    private function statusFilter(Request $request): ?ValidationStatus
    {
        $status = $request->query('status');

        if (! is_string($status) || $status === '') {
            return null;
        }

        abort_if(
            ValidationStatus::tryFrom($status) === null,
            422,
            'Unknown classification filter.',
        );

        return ValidationStatus::tryFrom($status);
    }

    /**
     * A task belonging to this account, or 404.
     *
     * 404 rather than 403 throughout: a 403 confirms the record exists, and the
     * identifiers here are sequential, so confirming existence is enough to walk
     * a whole tenant's task history.
     */
    private function owned(Request $request, mixed $extraction): Extraction
    {
        if (! is_numeric((string) $extraction)) {
            abort(404);
        }

        $record = Extraction::query()->find((int) $extraction);

        if ($record === null || (int) $record->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        return $record;
    }

    /**
     * Progress for a page of badges, keyed by extraction id.
     *
     * Computed in one pass rather than per row: a template calling a helper that
     * queries would issue a query per badge, which is twenty queries to render
     * twenty rows of arithmetic.
     *
     * @param  iterable<Extraction>  $extractions
     * @return array<int, TaskProgress>
     */
    private function progressMap(iterable $extractions): array
    {
        $map = [];

        foreach ($extractions as $extraction) {
            $map[(int) $extraction->id] = TaskProgress::of($extraction);
        }

        return $map;
    }

    /**
     * What to call a task the customer did not name.
     *
     * The source, in the customer's own words. A generated label containing the
     * current time would be worse than useless — two tasks submitted together
     * would be indistinguishable and neither would be findable.
     */
    private function taskName(?string $given, bool $isUrl): string
    {
        if ($given !== null && trim($given) !== '') {
            return mb_substr(trim($given), 0, 120);
        }

        return $isUrl ? 'Webpage addresses' : 'Pasted addresses';
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
}

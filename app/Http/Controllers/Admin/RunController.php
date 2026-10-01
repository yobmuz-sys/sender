<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\System\Runs\RunObserver;
use App\Domain\System\Runs\RunRecorder;
use App\Domain\Users\Permission;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Durable scheduled-run evidence.
 *
 * Read-only, and deliberately so. Runs are append-only records of what actually
 * happened; editing one would falsify the only evidence the cron capability is
 * derived from.
 */
class RunController extends Controller
{
    public function __construct(
        private readonly RunRecorder $runs,
        private readonly RunObserver $observer,
    ) {}

    public function __invoke(Request $request): View
    {
        abort_unless($request->user()?->can(Permission::JOBS_VIEW) ?? false, 403);

        // With no filter, show every command that counts as scheduler evidence
        // rather than only the worker. An operator diagnosing "did cron fire?"
        // needs the whole history, not just the most recent producer of it.
        $requested = trim($request->string('command')->toString());

        $runs = $requested === ''
            ? collect($this->observer->recent(100))
            : collect($this->runs->recent($requested, 100));

        return view('admin.runs.index', [
            'runs' => $runs,
            'command' => $requested,
            'commands' => $this->observer->probeCommands(),
            'latest' => $runs->first(),
            'freshAfter' => $this->runs->freshAfterSeconds(),
            'fresh' => $this->runs->isFresh($this->observer->probeCommand()),
        ]);
    }
}

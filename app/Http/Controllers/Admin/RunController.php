<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

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
    ) {}

    public function __invoke(Request $request): View
    {
        abort_unless($request->user()?->can(Permission::JOBS_VIEW) ?? false, 403);

        $runs = collect($this->runs->recent($request->string('command')->toString() ?: 'sender:heartbeat', 100));

        return view('admin.runs.index', [
            'runs' => $runs,
            'command' => $request->string('command')->toString() ?: 'sender:heartbeat',
            'latest' => $runs->first(),
            'freshAfter' => $this->runs->freshAfterSeconds(),
            'fresh' => $this->runs->isFresh('sender:heartbeat'),
        ]);
    }
}

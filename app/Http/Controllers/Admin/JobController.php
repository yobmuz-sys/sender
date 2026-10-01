<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\System\Capabilities\CapabilityRegistry;
use App\Domain\System\Enums\CapabilitySubject;
use App\Domain\System\Enums\DeploymentLimit;
use App\Domain\Users\Permission;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Operational monitoring.
 *
 * Explicitly not a job-management system. It reports the state of the Laravel
 * queue and of the platform's own scheduled runs, which is all the repository
 * can honestly say. There are no per-job controls because there is no product
 * job model — the `jobs` table is the stock framework queue and treating it as
 * a product domain would misrepresent what it is.
 */
class JobController extends Controller
{
    public function __construct(
        private readonly CapabilityRegistry $capabilities,
    ) {}

    public function __invoke(Request $request): View
    {
        abort_unless($request->user()?->can(Permission::JOBS_VIEW) ?? false, 403);

        return view('admin.jobs.index', [
            'driver' => (string) config('queue.default'),
            'queueCapability' => $this->capabilities->status(CapabilitySubject::Queue),
            'retryAfter' => (int) config('queue.connections.'.config('queue.default').'.retry_after', 0),
            'maxRuntime' => DeploymentLimit::MaxWorkerRuntimeSeconds->value(),
            'margin' => (int) config('sender.capabilities.queue.reservation_margin_seconds'),
            'maxBatchSize' => DeploymentLimit::MaxJobBatchSize->value(),
            'maxAttempts' => DeploymentLimit::MaxJobAttempts->value(),
            'pending' => $this->countQueue('jobs'),
            'failed' => $this->countQueue('failed_jobs'),
            'reserved' => $this->countQueue('jobs', whereNotNull: true),
        ]);
    }

    /**
     * The framework queue tables only exist on the database connection, and
     * only after Laravel's own migrations have run.
     */
    private function countQueue(string $table, bool $whereNotNull = false, string $column = 'reserved_at'): int
    {
        if (! DB::getSchemaBuilder()->hasTable($table)) {
            return 0;
        }

        $query = DB::table($table);

        if ($whereNotNull) {
            $query->whereNotNull($column);
        }

        return $query->count();
    }
}

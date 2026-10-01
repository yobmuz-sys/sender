<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\System\Capabilities\AvailabilityResolver;
use App\Domain\System\Capabilities\CapabilityRegistry;
use App\Domain\System\Enums\CapabilitySubject;
use App\Domain\System\Enums\DeploymentLimit;
use App\Domain\System\Flags\SubsystemFlagRegistry;
use App\Domain\Users\Permission;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * System overview.
 *
 * The first surface to carry environment detail, and it is deliberately behind
 * `system.view`. The environment name was previously printed in the footer of
 * every page, where a customer could read it; operational information belongs
 * with operators.
 */
class SystemController extends Controller
{
    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly AvailabilityResolver $availability,
        private readonly SubsystemFlagRegistry $flags,
    ) {}

    public function __invoke(Request $request): View
    {
        abort_unless($request->user()?->can(Permission::SYSTEM_VIEW) ?? false, 403);

        $report = $this->capabilities->report();

        return view('admin.system.index', [
            'overall' => $this->capabilities->overall(),
            'environment' => app()->environment(),
            'report' => $report,
            'subjects' => collect(CapabilitySubject::cases())
                ->mapWithKeys(fn (CapabilitySubject $subject): array => [
                    $subject->value => $this->capabilities->status($subject),
                ]),
            'availability' => $this->availability->resolveAll(),
            'flags' => $this->flags->all(),
            'diagnosticsEnabled' => (bool) config('sender.diagnostics.enabled'),
            'queueDriver' => (string) config('queue.default'),
            'retryAfter' => (int) config('queue.connections.'.config('queue.default').'.retry_after', 0),
            'maxRuntime' => DeploymentLimit::MaxWorkerRuntimeSeconds->value(),
        ]);
    }
}

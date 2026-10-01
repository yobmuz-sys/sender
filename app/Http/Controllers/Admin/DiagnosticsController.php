<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\System\Capabilities\AvailabilityResolver;
use App\Domain\System\Capabilities\CapabilityRegistry;
use App\Domain\Users\Permission;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The detailed diagnostics report, inside the administration area.
 *
 * Replaces the old top-level `/diagnostics`, which was reachable from
 * navigation but returned a 404 whenever `SENDER_DIAGNOSTICS_ENABLED` was
 * false. A link that 404s by configuration teaches operators to distrust the
 * navigation, so this page always renders and explains the disabled state
 * instead of disappearing.
 */
class DiagnosticsController extends Controller
{
    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly AvailabilityResolver $availability,
    ) {}

    public function __invoke(Request $request): View
    {
        abort_unless($request->user()?->can(Permission::SYSTEM_VIEW) ?? false, 403);

        $enabled = (bool) config('sender.diagnostics.enabled');

        return view('admin.system.diagnostics', [
            'enabled' => $enabled,
            'report' => $enabled ? $this->capabilities->report() : null,
            'capabilities' => $this->capabilities,
            // The registry's aggregate verdict, not the report's own. They are
            // not the same calculation — the report summarises host checks,
            // while this composes subject statuses and the required-capability
            // rule — so showing the report's figure here would contradict what
            // /health publishes on the same installation.
            'overall' => $this->capabilities->overall(),
            'availability' => $this->availability->resolveAll(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\System\Enums\Subsystem;
use App\Domain\System\Flags\SubsystemFlagRegistry;
use App\Domain\System\Settings\SystemSettings;
use App\Domain\Users\Permission;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Operator controls for the platform's subsystems.
 *
 * The first real consumer of {@see SubsystemFlagRegistry}. Emergency
 * disablement, safe mode and per-subsystem toggles are all this one persisted
 * flag, and this page mutates only that. No parallel "emergency" table or cache
 * flag is introduced, because two sources of truth for "is this stopped" would
 * disagree exactly when it matters most.
 */
class SubsystemController extends Controller
{
    public function __construct(
        private readonly SubsystemFlagRegistry $flags,
        private readonly SystemSettings $settings,
    ) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()?->can(Permission::SYSTEM_VIEW) ?? false, 403);

        return view('admin.system.subsystems', [
            'subsystems' => collect(Subsystem::cases())->map(fn (Subsystem $subsystem): array => [
                'enum' => $subsystem,
                'enabled' => $this->flags->enabled($subsystem),
                'default' => (bool) config('sender.subsystems.'.$subsystem->value, true),
                'overridden' => $this->settings->get($this->key($subsystem)) !== null,
                'subject' => $subsystem->subject(),
                'permittedByEnvironment' => $subsystem->deploymentAllows(),
            ]),
            'canManage' => $request->user()?->can(Permission::SYSTEM_MANAGE) ?? false,
        ]);
    }

    public function enable(Request $request, Subsystem $subsystem): RedirectResponse
    {
        abort_unless($this->mayManage($request), 403);

        $this->flags->enable($subsystem);

        return back()->with('status', "{$subsystem->label()} has been enabled.");
    }

    public function disable(Request $request, Subsystem $subsystem): RedirectResponse
    {
        abort_unless($this->mayManage($request), 403);

        $this->flags->disable($subsystem);

        return back()->with('status', "{$subsystem->label()} has been disabled. Operations that need it will refuse to run.");
    }

    public function reset(Request $request, Subsystem $subsystem): RedirectResponse
    {
        abort_unless($this->mayManage($request), 403);

        $this->flags->reset($subsystem);

        return back()->with('status', "{$subsystem->label()} has been returned to its configured default.");
    }

    private function mayManage(Request $request): bool
    {
        return $request->user()?->can(Permission::SYSTEM_MANAGE) ?? false;
    }

    private function key(Subsystem $subsystem): string
    {
        return 'subsystem.'.$subsystem->value.'.enabled';
    }
}

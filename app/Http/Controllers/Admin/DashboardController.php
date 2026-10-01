<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\System\Capabilities\AvailabilityResolver;
use App\Domain\System\Capabilities\CapabilityRegistry;
use App\Domain\System\Enums\CapabilitySubject;
use App\Domain\System\Runs\RunRecorder;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Enums\UserStatus;
use App\Domain\Users\Permission;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The administrative landing page.
 *
 * Shows only what the repository can actually measure. Nothing here is
 * projected forward: there are no campaign counts, no delivery figures and no
 * extraction totals, because inventing a plausible-looking number for a domain
 * that does not exist is worse than leaving the row out.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly AvailabilityResolver $availability,
        private readonly RunRecorder $runs,
    ) {}

    public function __invoke(Request $request): View
    {
        abort_unless($request->user()?->can(Permission::ADMIN_VIEW) ?? false, 403);

        $recentRuns = collect($this->runs->recent('sender:heartbeat', 10));

        $staffRoles = array_values(array_map(
            static fn (Role $role): string => $role->value,
            array_filter(Role::cases(), static fn (Role $role): bool => $role->isStaff()),
        ));

        return view('admin.dashboard', [
            'overall' => $this->capabilities->overall(),
            'subjects' => collect(CapabilitySubject::cases())
                ->mapWithKeys(fn (CapabilitySubject $subject): array => [
                    $subject->value => $this->capabilities->status($subject),
                ]),
            'availability' => $this->availability->resolveAll(),
            'userTotal' => User::query()->count(),
            'staffTotal' => User::query()->whereIn('role', $staffRoles)->count(),
            'suspendedTotal' => User::query()->where('status', UserStatus::Suspended->value)->count(),
            'recentUsers' => User::query()->latest()->limit(5)->get(),
            'recentRuns' => $recentRuns->take(5),
            'latestRun' => $recentRuns->first(),
            'failedRunCount' => $recentRuns->where(static fn ($run): bool => ! $run->succeeded())->count(),
        ]);
    }
}

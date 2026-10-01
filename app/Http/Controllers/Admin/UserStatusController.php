<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Users\Enums\UserStatus;
use App\Domain\Users\LastSuperAdministratorException;
use App\Domain\Users\SuperAdministratorGuard;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Suspending and reinstating an account.
 *
 * Separate from {@see UserController} because these are not edits: they change
 * whether someone can sign in at all, and they must go through the lockout
 * guard rather than through a form update.
 */
class UserStatusController extends Controller
{
    public function __construct(
        private readonly SuperAdministratorGuard $guard,
    ) {}

    public function store(User $user): RedirectResponse
    {
        $this->authorizeAction($user);

        try {
            $this->guard->assertMayChange($user, null, UserStatus::Suspended);
        } catch (LastSuperAdministratorException $exception) {
            return back()->withErrors(['status' => $exception->getMessage()]);
        }

        // Transactional because the status change and the session eviction must
        // agree: a committed suspension with live sessions would be reported as
        // complete while the account stayed usable.
        DB::transaction(function () use ($user): void {
            $user->forceFill(['status' => UserStatus::Suspended])->save();
            $this->deleteSessions($user);
        });

        return back()->with('status', "{$user->name} has been suspended.");
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->authorizeAction($user);

        try {
            $this->guard->assertMayChange($user, null, UserStatus::Active);
        } catch (LastSuperAdministratorException $exception) {
            return back()->withErrors(['status' => $exception->getMessage()]);
        }

        $user->forceFill(['status' => UserStatus::Active])->save();

        return back()->with('status', "{$user->name} has been reinstated.");
    }

    /**
     * A suspended account is not reachable by anyone, including an
     * administrator who holds only view access, so this is checked against the
     * route's own permission as well.
     */
    private function authorizeAction(User $user): void
    {
        $request = request();

        abort_unless($request->user()?->can('users.suspend') ?? false, 403);

        // Removing your own access is almost always a mistake and always
        // unrecoverable from inside the application.
        if ($user->is($request->user())) {
            throw ValidationException::withMessages([
                'status' => 'You cannot change the access of your own account here.',
            ]);
        }
    }

    private function deleteSessions(User $user): void
    {
        DB::table('sessions')
            ->where('user_id', $user->getAuthIdentifier())
            ->delete();
    }
}

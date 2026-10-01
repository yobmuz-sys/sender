<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Users\Enums\Role;
use App\Domain\Users\SuperAdministratorGuard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * User administration.
 *
 * The only administrative area that is genuinely functional at this stage,
 * because `users` is the one table with a real persistence model behind it.
 */
class UserController extends Controller
{
    public function __construct(
        private readonly SuperAdministratorGuard $guard,
    ) {}

    public function index(Request $request): View
    {
        $users = User::query()
            ->when($request->string('search')->isNotEmpty(), static function ($query) use ($request): void {
                $term = '%'.$request->string('search')->trim().'%';

                // Escape LIKE wildcards so a literal % in the search box does
                // not silently match everything.
                $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);

                $query->where(static function ($query) use ($term): void {
                    $query->where('name', 'like', $term)->orWhere('email', 'like', $term);
                });
            })
            ->when($request->filled('role'), static fn ($query) => $query->where('role', $request->string('role')->toString()))
            ->when($request->filled('status'), static fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'roles' => Role::cases(),
            'search' => $request->string('search')->toString(),
            'roleFilter' => $request->string('role')->toString(),
            'statusFilter' => $request->string('status')->toString(),
        ]);
    }

    public function create(): View
    {
        return view('admin.users.create', ['roles' => Role::cases()]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $data = $request->safe()->only(['name', 'email', 'password']);

        $user = User::create([
            ...$data->toArray(),
            // Assigned explicitly rather than mass assigned: the fillable list
            // deliberately excludes role so a registration request cannot set
            // it, and this path is the one that is allowed to.
            'role' => $request->enum('role', Role::class),
        ]);

        return redirect()
            ->route('admin.users.show', $user)
            ->with('status', "{$user->name} has been created.");
    }

    public function show(User $user): View
    {
        return view('admin.users.show', ['user' => $user]);
    }

    public function edit(User $user): View
    {
        return view('admin.users.edit', ['user' => $user, 'roles' => Role::cases()]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $newRole = $request->enum('role', Role::class);

        // Demoting the last super administrator would leave the deployment with
        // no way to administer itself, so the guard is consulted on this path
        // exactly as it is on suspension.
        $refusal = $this->guard->refusalFor($user, $newRole);

        if ($refusal !== null) {
            return back()->withErrors(['role' => $refusal])->withInput();
        }

        $user->fill($request->safe()->only(['name', 'email']));

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
            $user->sendEmailVerificationNotification();
        }

        $user->role = $newRole;

        if ($request->filled('password')) {
            // Hashed by the model cast; never assigned in raw form.
            $user->password = (string) $request->string('password')->toString();
        }

        $user->save();

        return redirect()
            ->route('admin.users.show', $user)
            ->with('status', "{$user->name} has been updated.");
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * The signed-in account's own settings.
 *
 * Deliberately narrow: name, address and password. Role, verification state and
 * suspension are operator concerns and are changed from the administration area.
 */
class AccountController extends Controller
{
    public function profile(Request $request): View
    {
        return view('account.profile', ['user' => $request->user()]);
    }

    public function updateProfile(UpdateProfileRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->fill($request->safe()->only(['name', 'email']));

        // Changing the address invalidates a previous confirmation, so the
        // account must confirm the new one before it is trusted again.
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
            $user->sendEmailVerificationNotification();
        }

        $user->save();

        return back()->with('status', 'Your profile has been updated.');
    }

    public function security(Request $request): View
    {
        return view('account.security', ['user' => $request->user()]);
    }

    public function updatePassword(UpdatePasswordRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->password = Hash::make((string) $request->string('password')->toString());
        $user->save();

        // Everything else on this session was authorised with the old
        // credential, so invalidate the rest of them.
        $request->session()->regenerate();

        return back()->with('status', 'Your password has been changed.');
    }
}

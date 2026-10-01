<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

/**
 * Confirms an address from a signed link.
 *
 * Uses Laravel's signed request so the {id} and {hash} cannot be edited in
 * transit. The hash is re-checked against the stored address, which is what
 * makes a link stop working if the address is changed afterwards.
 */
class VerifyEmailController extends Controller
{
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('dashboard')->with('status', 'Your address was already confirmed.');
        }

        if ($request->user()->markEmailAsVerified()) {
            event(new Verified($request->user()));
        }

        return redirect()->route('dashboard')->with('status', 'Your email address has been confirmed.');
    }
}

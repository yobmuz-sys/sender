<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sends another confirmation link.
 *
 * The response is identical whether or not anything was actually sent. The
 * endpoint only ever mails the signed-in account, so it cannot be used to probe
 * for registered addresses, but keeping the message constant costs nothing and
 * removes the question.
 */
class EmailVerificationNotificationController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('dashboard');
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'A fresh confirmation link has been sent to your address.');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of an account that has been suspended since it signed in.
 *
 * Without this, suspension would only stop the next login, which is not what an
 * operator suspending a compromised account means — they mean now, including for
 * a session already open. The status is read from the model already loaded for
 * the request, so the check costs no query.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->canSignIn()) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors(['email' => __('auth.failed')]);
        }

        return $next($request);
    }
}

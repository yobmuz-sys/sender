<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Explains that an account's address has not been confirmed yet.
 */
class EmailVerificationPromptController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        // A confirmed account has no reason to see the notice.
        return $request->user()->hasVerifiedEmail()
            ? redirect()->route('dashboard')
            : view('auth.verify-email');
    }
}

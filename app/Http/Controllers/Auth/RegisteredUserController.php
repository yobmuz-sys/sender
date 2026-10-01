<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $user = $request->createUser();

        // Land on the confirmation notice rather than the dashboard. Sending
        // someone straight into the product after registering, with no signal
        // that the address is unconfirmed, is how unverified accounts quietly
        // accumulate.
        return redirect()->route('verification.notice');
    }
}

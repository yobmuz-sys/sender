<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            // Confirmed passwords require a matching `password_confirmation`
            // field, and the minimum length comes from the application config
            // so shared-hosting deployments can raise it without code changes.
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @throws ValidationException
     */
    public function createUser(): User
    {
        $user = User::create([
            'name' => $this->string('name')->toString(),
            'email' => $this->string('email')->toString(),
            'password' => $this->string('password')->toString(),
        ]);

        // The `role` column is not mass assignable, so a new account is
        // always a plain customer account. Privilege is granted by an
        // operator, never by a registration request.
        event(new Registered($user));

        Auth::login($user);

        return $user;
    }
}

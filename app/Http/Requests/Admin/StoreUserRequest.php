<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Users\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Creating an account from the administration area.
 *
 * Accepts a role deliberately: an administrator is the one person allowed to
 * decide what an account is. The registration form does not, because a customer
 * must never be able to grant themselves a role.
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('users.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => ['required', Rule::enum(Role::class)],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * A password change from the security page.
 *
 * Requires the current password so a stolen session alone cannot lock the real
 * owner out, and refuses to repeat the existing one so the form cannot be used
 * to change nothing while appearing to have succeeded.
 */
class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults(), 'different:current_password'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // A new password identical to the current one is not a change.
            // Reporting success would be a lie that matters when somebody
            // believes they have secured an account they did not.
            if (password_verify((string) $this->input('password'), (string) $this->user()->password)) {
                $validator->errors()->add(
                    'password',
                    'Choose a password you have not used for this account.',
                );
            }
        });
    }
}

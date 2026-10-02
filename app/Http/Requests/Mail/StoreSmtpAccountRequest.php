<?php

declare(strict_types=1);

namespace App\Http\Requests\Mail;

use App\Domain\Mail\SmtpAccount;
use App\Domain\Mail\SmtpAuthMode;
use App\Domain\Mail\SmtpEncryption;
use App\Domain\Mail\SmtpManagementMode;
use App\Domain\Mail\SmtpProvider;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating or editing one SMTP transport.
 *
 * Shared by the customer and administrative forms because they configure the
 * same thing; they differ in *who* may submit it, which the controllers decide.
 * Two separate request classes would let the two drift on what counts as a valid
 * port or an acceptable From address, and a tenant would be able to save a
 * configuration an operator could not.
 *
 * The secret is validated but never echoed: a failed submission must not render
 * the password back into the form, and the controller does not put it in
 * `withInput()`.
 */
class StoreSmtpAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Controllers gate on ownership or permission before resolving the
        // account. This returns true so a request that reached validation is
        // not rejected twice by two different mechanisms.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $account = $this->route('account');
        $id = $account instanceof SmtpAccount ? $account->getKey() : null;

        return [
            'label' => ['required', 'string', 'max:100'],
            'provider' => ['required', Rule::enum(SmtpProvider::class)],

            // A tenant's own account is always user_managed; an operator chooses.
            'management_mode' => ['nullable', Rule::enum(SmtpManagementMode::class)],

            'host' => ['required', 'string', 'max:253', 'not_regex:/\s/'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['required', Rule::enum(SmtpEncryption::class)],
            'auth_mode' => ['required', Rule::enum(SmtpAuthMode::class)],
            'username' => ['nullable', 'string', 'max:320'],
            'secret' => ['nullable', 'string', 'max:4096'],

            'from_address' => ['required', 'string', 'email', 'max:320'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'reply_to' => ['nullable', 'string', 'email', 'max:320'],
            'dkim_selector' => ['nullable', 'string', 'max:63', 'regex:/^[a-zA-Z0-9_-]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'dkim_selector' => 'DKIM selector',
            'from_address' => 'From address',
            'reply_to' => 'Reply-To address',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dkim_selector.regex' => 'A DKIM selector may contain only letters, numbers, underscores and hyphens.',
        ];
    }

    /**
     * Cross-field checks the field rules cannot express.
     *
     * Each of these encodes a rule that would otherwise be enforced at different
     * places, or not at all:
     *
     *   - password authentication with no password is unusable, and would only
     *     fail later against the server
     *   - a stored secret on an unauthenticated transport would sit in the
     *     database unused, implying a configuration that is not in effect
     *   - the From address must be one this transport authenticated as, or the
     *     platform becomes an open relay for somebody else's identity
     *
     * @return array<int, callable(Validator): void>
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $authMode = SmtpAuthMode::tryFrom((string) $this->input('auth_mode'));

            if ($authMode === SmtpAuthMode::Password) {
                $account = $this->route('account');

                $hasStored = $account instanceof SmtpAccount && $account->hasSecret();

                if (! $hasStored && blank($this->input('secret'))) {
                    $validator->errors()->add(
                        'secret',
                        'A password is required when the transport authenticates with a username.',
                    );
                }
            }

            if ($authMode === SmtpAuthMode::None && filled($this->input('secret'))) {
                $validator->errors()->add(
                    'secret',
                    'A password cannot be stored for a transport that sends no authentication, because it would never be used.',
                );
            }

            $username = trim((string) $this->input('username'));
            $from = trim((string) $this->input('from_address'));

            if ($username !== '' && $from !== '' && mb_strtolower($username) !== mb_strtolower($from)) {
                $validator->errors()->add(
                    'from_address',
                    'The From address must be the same address the transport authenticates as. '
                        .'Sending as an address this transport did not authenticate as is not permitted.',
                );
            }
        });
    }
}

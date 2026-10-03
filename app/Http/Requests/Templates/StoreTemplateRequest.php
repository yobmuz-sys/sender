<?php

declare(strict_types=1);

namespace App\Http\Requests\Templates;

use App\Domain\Templates\MessageRenderer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating or editing one reusable message template.
 *
 * One class for both, because they validate the same document. Two would let them
 * disagree about what a valid template is, and a customer could save through the
 * edit form something the create form would have refused.
 *
 * Three of these rules are the reason this class is not a list of strings:
 *
 *   - a placeholder the platform does not fill in is refused, rather than stored
 *     and sent to recipients as literal text;
 *   - anything that looks like an attempt to run code is refused outright, because
 *     a template is content and there is no legitimate reason for `<?php` or
 *     `{!! !!}` to appear in an email body;
 *   - name uniqueness is per tenant, so two accounts can both keep a template
 *     called "October update" without either being told to rename.
 */
class StoreTemplateRequest extends FormRequest
{
    /**
     * Length ceilings, in characters.
     *
     * Named and local rather than configured, matching every other content form in
     * the product. They are not operational knobs: nothing about how this
     * installation is deployed changes how long a subject line may be, and a
     * value an operator can only discover by reading this file is worse than one
     * they never need to change.
     *
     * The bodies are the generous ones. A template is rendered in the customer's
     * own browser on the preview page, so the ceiling that matters is the size of
     * the page somebody is willing to wait for — not a server's patience.
     */
    private const MAX_NAME = 120;

    private const MAX_SUBJECT = 255;

    private const MAX_PREHEADER = 255;

    private const MAX_HTML_BODY = 262144;

    private const MAX_TEXT_BODY = 262144;

    public function authorize(): bool
    {
        // Ownership is enforced by the controller before the record is resolved.
        // Returning true here means a request that reached validation is not
        // rejected a second time by a second mechanism.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:'.self::MAX_NAME,
                Rule::unique('templates', 'name')
                    ->where('user_id', $this->user()?->id)
                    // Renaming a template to the name it already has must not be an
                    // error. A form that fails when nothing changed is a form people
                    // stop believing.
                    //
                    // The route parameter is the raw id rather than a model: these
                    // controllers resolve the record themselves so that another
                    // tenant's template is a 404 rather than a binding failure, and
                    // the id is all the uniqueness check needs.
                    ->ignore(is_numeric((string) $this->route('template'))
                        ? (int) $this->route('template')
                        : null),
            ],

            'subject' => ['required', 'string', 'max:'.self::MAX_SUBJECT],
            'preheader' => ['nullable', 'string', 'max:'.self::MAX_PREHEADER],
            'html_body' => ['required', 'string', 'max:'.self::MAX_HTML_BODY],
            'text_body' => ['required', 'string', 'max:'.self::MAX_TEXT_BODY],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'html_body' => 'HTML message',
            'text_body' => 'plain-text message',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'You already have a template with that name.',
        ];
    }

    /**
     * The content checks that a field rule cannot express.
     *
     * @return array<int, callable(Validator): void>
     */
    public function withValidator(Validator $validator): void
    {
        $renderer = app(MessageRenderer::class);

        $validator->after(function (Validator $validator) use ($renderer): void {
            foreach (['subject', 'preheader', 'html_body', 'text_body'] as $field) {
                $content = (string) $this->input($field);

                if ($content === '') {
                    // Already reported as required or nullable. Reporting an
                    // empty-field placeholder list as well would be noise.
                    continue;
                }

                foreach ($renderer->unknownTokens($content) as $token) {
                    $validator->errors()->add(
                        $field,
                        'The placeholder {{'.$token.'}} is not one this platform fills in. '
                            .'Supported placeholders are {{email}}, {{first_name}} and {{unsubscribe_url}}.',
                    );
                }

                if ($renderer->containsExecutableSyntax($content)) {
                    $validator->errors()->add(
                        $field,
                        'This looks like an attempt to run code. A template is message content: '
                            .'it is never compiled, so PHP, Blade and raw output tags cannot be used in it.',
                    );
                }
            }
        });
    }
}

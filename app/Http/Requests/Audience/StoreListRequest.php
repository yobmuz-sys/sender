<?php

declare(strict_types=1);

namespace App\Http\Requests\Audience;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A list's name and description.
 *
 * Name is required and capped at 120 characters, because a list name is a label
 * on a page and is read at a glance. Two hundred characters of it is a paragraph
 * and stops being a label.
 *
 * Uniqueness is scoped to the tenant, not global. Two accounts must be able to
 * both have a list called "Leads" — they are separate audiences belonging to
 * separate customers, and one customer's naming choice is not the other's
 * business.
 */
class StoreListRequest extends FormRequest
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
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('contact_lists', 'name')
                    ->where('user_id', $this->user()?->id)
                    // On an update, exclude this list from its own uniqueness
                    // check — otherwise renaming a list to its current name
                    // fails, which is the sort of thing that makes people stop
                    // trusting a form.
                    ->ignore($this->route('list')?->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'You already have a list with that name.',
        ];
    }
}

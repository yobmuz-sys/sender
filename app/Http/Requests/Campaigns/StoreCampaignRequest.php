<?php

declare(strict_types=1);

namespace App\Http\Requests\Campaigns;

use App\Domain\Campaigns\Campaign;
use App\Domain\Campaigns\CampaignPreflight;
use App\Domain\Mail\SmtpAccount;
use App\Domain\Templates\Template;
use App\Models\ContactList;
use App\Support\Timezone;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * A campaign's configuration: what to send, through what, to whom, at what pace.
 *
 * Every reference is validated as belonging to *this* account, not merely as
 * existing. A campaign points at a template, a transport and a list, and each of
 * those is a record a customer could name by id; accepting somebody else's id
 * would let one tenant's message be sent from another tenant's transport, to
 * another tenant's audience, under this platform.
 *
 * The checks that are *not* here are the ones the preflight owns: whether the
 * template is complete, whether the transport is verified, whether anybody is
 * actually eligible. They are answered by {@see CampaignPreflight}
 * at launch, because a form that refused to save a work-in-progress campaign would
 * be a form that could not express "not yet", and the customer needs to be able to
 * prepare something before it is sendable.
 */
class StoreCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ownership of the campaign being edited is settled by the controller
        // before validation runs. Returning true avoids rejecting the same request
        // twice by two different mechanisms.
        return true;
    }

    /**
     * Turn the submitted wall-clock into the instant it names.
     *
     * A `datetime-local` input carries no zone: "2026-10-10T09:00" is nine in the
     * morning *somewhere*, and until it is paired with the zone the customer chose
     * it is not an instant at all. This runs before validation so the `after:now`
     * rule, the stored timestamp and the worker's comparison are all looking at the
     * same moment — and so a schedule entered as 09:00 Europe/London is stored as
     * 09:00 UTC in winter and 08:00 UTC in summer, which is what the customer meant.
     *
     * A value that cannot be parsed is left alone, so the `date` rule reports it as
     * a field error rather than this throwing on input.
     */
    protected function prepareForValidation(): void
    {
        $input = $this->input('scheduled_at');
        $timezone = (string) ($this->input('scheduled_timezone') ?: Timezone::default());

        if (! is_string($input) || trim($input) === '' || ! Timezone::isValid($timezone)) {
            return;
        }

        try {
            $moment = CarbonImmutable::createFromFormat('Y-m-d\TH:i', trim($input), $timezone);
        } catch (Throwable) {
            return;
        }

        if ($moment === false || $moment === null) {
            return;
        }

        $this->merge([
            'scheduled_at' => $moment->utc()->toDateTimeString(),
            'scheduled_timezone' => $timezone,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenant = $this->user()?->id;

        return [
            'name' => ['required', 'string', 'max:120'],

            'template_id' => [
                'required',
                'integer',
                Rule::exists('templates', 'id')->where('user_id', $tenant),
            ],

            'list_id' => [
                'required',
                'integer',
                Rule::exists('contact_lists', 'id')->where('user_id', $tenant),
            ],

            'smtp_account_id' => [
                'required',
                'integer',
                Rule::exists('smtp_accounts', 'id')->where('user_id', $tenant),
            ],

            'rate_interval_seconds' => ['required', 'integer', 'min:1', 'max:3600'],

            'worker_batch_size' => ['required', 'integer', 'min:1', 'max:100'],

            // Sending now versus at a time the customer chooses. `scheduled_at` is
            // optional rather than required-with-one-of, because a campaign with no
            // start time is a campaign that starts when it is launched.
            //
            // Validated against the offered list rather than merely "a string", so a
            // hand-built post cannot name a zone the builder never showed.
            'scheduled_at' => ['nullable', 'date', 'after:now'],

            'scheduled_timezone' => ['nullable', 'string', Rule::in(array_keys(Timezone::options()))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'template_id' => 'template',
            'list_id' => 'list',
            'smtp_account_id' => 'sending transport',
            'rate_interval_seconds' => 'minimum send interval',
            'worker_batch_size' => 'messages per worker run',
            'scheduled_at' => 'start time',
            'scheduled_timezone' => 'time zone',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'template_id.exists' => 'Choose one of your own templates.',
            'list_id.exists' => 'Choose one of your own lists.',
            'smtp_account_id.exists' => 'Choose one of your own sending transports.',
            'scheduled_at.after' => 'Choose a time in the future, or start immediately.',
            'scheduled_timezone.in' => 'Choose a time zone from the list.',
        ];
    }

    /**
     * Cross-field checks a field rule cannot express.
     *
     * @return array<int, callable(Validator): void>
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $campaign = $this->route('campaign');

            if (! $campaign instanceof Campaign || $campaign->isConfigurable()) {
                return;
            }

            // Once a campaign has launched, its configuration is frozen. A form
            // that silently accepted edits would let a customer believe they had
            // changed what a running campaign is sending.
            foreach (['template_id', 'list_id', 'smtp_account_id'] as $field) {
                $requested = $this->input($field);

                if ($requested !== null && (int) $requested !== (int) $campaign->{$field}) {
                    $validator->errors()->add(
                        $field,
                        'This campaign is already sending, so what it sends cannot be changed. '
                            .'Cancel it and create a new one instead.',
                    );
                }
            }
        });
    }

    /**
     * The template this request names, when it names one of this account's.
     */
    public function template(): ?Template
    {
        $id = $this->input('template_id');

        return is_numeric($id)
            ? Template::query()->where('user_id', $this->user()?->id)->find((int) $id)
            : null;
    }

    /**
     * The list this request names, when it names one of this account's.
     */
    public function list(): ?ContactList
    {
        $id = $this->input('list_id');

        return is_numeric($id)
            ? ContactList::query()->where('user_id', $this->user()?->id)->find((int) $id)
            : null;
    }

    /**
     * The transport this request names, when it names one of this account's.
     */
    public function smtpAccount(): ?SmtpAccount
    {
        $id = $this->input('smtp_account_id');

        return is_numeric($id)
            ? SmtpAccount::query()->where('user_id', $this->user()?->id)->find((int) $id)
            : null;
    }
}

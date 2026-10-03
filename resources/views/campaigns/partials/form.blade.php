@use('App\Domain\Templates\TemplateStatus')
@use('App\Support\Timezone')

@php
    /**
     * The campaign form, shared by create and edit.
     *
     * One markup tree for both screens, so a field cannot exist on one and not the
     * other, which is how a customer ends up saving a campaign through the edit
     * form that the create form would have refused.
     *
     * `$campaign` is always present: a new, unsaved model on the create page and
     * the stored one on the edit page. The view never has to guess which it is
     * beyond the `$editing` flag below.
     *
     * A campaign that has launched is never given this form at all: the controller
     * answers 409 rather than rendering fields whose changes would be ignored.
     */
    $editing = $campaign->exists;
    $report = $preflight->reportFor($campaign);

    // The chosen template and transport, loaded once and shown in full beneath
    // their selects. Reading the template's own status and blockers here rather
    // than the campaign preflight's is deliberate: the panel answers "what did I
    // pick", and the preflight answers "can this go out". They are different
    // questions, and the second one is the only one that may block a send.
    $chosenTemplate = $templates->firstWhere('id', $campaign->template_id);
    $chosenAccount = $accounts->firstWhere('id', $campaign->smtp_account_id);
@endphp

<form method="POST"
      action="{{ $editing ? route('campaigns.update', $campaign) : route('campaigns.store') }}"
      data-campaign-refresh>
    @csrf
    @if ($editing)
        @method('PUT')
    @endif

    <div class="space-y-6">
        <x-card title="1. The campaign">
            <div class="max-w-xl space-y-4">
                <div>
                    <x-input-label for="name">Campaign name</x-input-label>
                    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                                  :value="old('name', $campaign->name)"
                                  placeholder="October update" required autofocus />
                    <x-input-error class="mt-1" :messages="$errors->get('name')" />
                    <p class="mt-1 text-xs text-slate-500">Only you see this. Recipients see the subject line.</p>
                </div>

                <div>
                    <x-input-label for="template_id">Template</x-input-label>
                    <select id="template_id" name="template_id" required
                            class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                        <option value="">Choose a template</option>
                        @foreach ($templates as $template)
                            <option value="{{ $template->id }}"
                                    @selected(old('template_id', $campaign->template_id) == $template->id)>
                                {{ $template->name }} - version {{ $template->version }} - {{ $template->subject }}
                            </option>
                        @endforeach
                    </select>
                    <x-input-error class="mt-1" :messages="$errors->get('template_id')" />

                    @if ($templates->isEmpty())
                        <p class="mt-1 text-xs text-amber-800">
                            You have no templates yet. A campaign sends the words a template holds, so one is needed.
                        </p>
                    @else
                        <p class="mt-1 text-xs text-slate-500">
                            The campaign keeps its own copy of this template when it starts. Editing the template
                            afterwards will not change what this campaign sends.
                        </p>

                        @if ($chosenTemplate !== null)
                            @include('campaigns.partials.template-detail', ['template' => $chosenTemplate])
                        @endif
                    @endif
                </div>

                <div>
                    <x-input-label for="smtp_account_id">Sending transport</x-input-label>
                    <select id="smtp_account_id" name="smtp_account_id" required
                            class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                        <option value="">Choose a transport</option>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}"
                                    @selected(old('smtp_account_id', $campaign->smtp_account_id) == $account->id)>
                                {{ $account->label }} - {{ $account->host }} - {{ $account->effectiveStatus()->label() }}
                            </option>
                        @endforeach
                    </select>
                    <x-input-error class="mt-1" :messages="$errors->get('smtp_account_id')" />

                    <p class="mt-1 text-xs text-slate-500">
                        One campaign sends through one transport. If that transport stops working, the campaign
                        stops; it is never quietly switched to another account.
                    </p>

                    @if ($chosenAccount !== null)
                        @include('campaigns.partials.transport-detail', ['account' => $chosenAccount])
                    @endif
                </div>
            </div>
        </x-card>

        <x-card title="2. The audience">
            <div class="max-w-xl">
                <x-input-label for="list_id">List</x-input-label>
                <select id="list_id" name="list_id" required
                        class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                    <option value="">Choose a list</option>
                    @foreach ($lists as $list)
                        <option value="{{ $list->id }}" @selected(old('list_id', $campaign->list_id) == $list->id)>
                            {{ $list->name }}
                        </option>
                    @endforeach
                </select>
                <x-input-error class="mt-1" :messages="$errors->get('list_id')" />

                <p class="mt-1 text-xs text-slate-500">
                    The campaign takes its own copy of the contacts on this list when it starts. Changing the
                    list afterwards will not change who this campaign contacts.
                </p>
            </div>

            @if ($campaign->list_id !== null && $campaign->list !== null)
                @php($summary = $audience->summaryFor($campaign))

                <dl class="mt-4 grid gap-3 sm:grid-cols-3">
                    @foreach ($summary->figures() as $figure)
                        <div class="rounded-md bg-slate-50 px-3 py-2 ring-1 ring-inset ring-slate-200">
                            <dt class="text-xs text-slate-600">{{ $figure['label'] }}</dt>
                            <dd class="mt-0.5 text-lg font-semibold text-slate-900">{{ number_format($figure['value']) }}</dd>
                            <p class="text-xs text-slate-500">{{ $figure['hint'] }}</p>
                        </div>
                    @endforeach
                </dl>

                @if ($summary->isEmpty())
                    <x-alert variant="warning" class="mt-4">
                        Nobody on this list can be contacted right now, so there is nothing to send to. Each
                        contact must be likely active, must have evidence they agreed, and must not have asked
                        not to be contacted.
                    </x-alert>
                @endif
            @endif
        </x-card>

        <x-card title="3. When and how fast">
            <div class="max-w-xl space-y-4">
                <div>
                    <x-input-label for="scheduled_at">Start</x-input-label>
                    <input id="scheduled_at" name="scheduled_at" type="datetime-local"
                           value="{{ old('scheduled_at', $campaign->scheduledLocalTime()?->format('Y-m-d\TH:i')) }}"
                           class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500" />
                    <x-input-error class="mt-1" :messages="$errors->get('scheduled_at')" />
                    <p class="mt-1 text-xs text-slate-500">
                        Leave this empty to start as soon as the campaign is started. A time in the future means
                        it waits until the worker next runs after that moment.
                    </p>
                </div>

                <div>
                    <x-input-label for="scheduled_timezone">Time zone</x-input-label>
                    <select id="scheduled_timezone" name="scheduled_timezone"
                            class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                        @php($zones = Timezone::options())
                        @foreach ($zones as $zone => $zoneLabel)
                            <option value="{{ $zone }}"
                                    @selected(old('scheduled_timezone', $campaign->scheduled_timezone ?? Timezone::default()) === $zone)>
                                {{ $zoneLabel }}
                            </option>
                        @endforeach
                    </select>
                    <x-input-error class="mt-1" :messages="$errors->get('scheduled_timezone')" />

                    <p class="mt-1 text-xs text-slate-500">
                        The clock above is read in this zone, so 09:00 here is 09:00 where you are. It is
                        remembered with the campaign and shown back to you the same way. The offset in brackets
                        is today's, which is what tells you whether a summer campaign will move an hour.
                    </p>
                </div>

                <div>
                    <x-input-label for="rate_interval_seconds">Minimum send interval, in seconds</x-input-label>
                    <x-text-input id="rate_interval_seconds" name="rate_interval_seconds" type="number"
                                  min="1" max="3600" class="mt-1 block w-full"
                                  :value="old('rate_interval_seconds', $campaign->rate_interval_seconds)"
                                  required />
                    <x-input-error class="mt-1" :messages="$errors->get('rate_interval_seconds')" />

                    <p class="mt-1 text-xs text-slate-500">
                        The shortest gap this campaign leaves between two messages. It is a minimum, not a
                        schedule: your hosting scheduler decides when the worker actually runs, so on a host
                        that wakes it every five minutes, a 30-second interval still means roughly one message
                        every five minutes.
                    </p>
                </div>

                <div>
                    <x-input-label for="worker_batch_size">Messages per worker run</x-input-label>
                    <x-text-input id="worker_batch_size" name="worker_batch_size" type="number"
                                  min="1" max="100" class="mt-1 block w-full"
                                  :value="old('worker_batch_size', $campaign->worker_batch_size)"
                                  required />
                    <x-input-error class="mt-1" :messages="$errors->get('worker_batch_size')" />
                    <p class="mt-1 text-xs text-slate-500">
                        How many messages one worker may attempt before it must exit. Higher is not faster: the
                        interval above still applies between them.
                    </p>
                </div>
            </div>
        </x-card>

        <x-card title="4. Checks"
                description="Answered from this campaign's current state. Starting runs exactly these checks again on the server.">
            @include('campaigns.partials.preflight', ['report' => $report])

            <div class="mt-5 flex flex-wrap items-center gap-3 border-t border-slate-200 pt-4">
                <x-primary-button :disabled="! $report->canLaunch()">Start campaign</x-primary-button>

                <x-button variant="secondary" type="submit" name="start" value="0">Save draft</x-button>

                @unless ($report->canLaunch())
                    <span class="text-sm text-slate-600">
                        Starting is unavailable until every blocking check passes.
                    </span>
                @endunless
            </div>
        </x-card>
    </div>
</form>
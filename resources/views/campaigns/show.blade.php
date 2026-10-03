@use('App\Domain\Campaigns\CampaignOperations')
@use('App\Domain\Campaigns\CampaignRecipientStatus')
@use('App\Domain\Campaigns\CampaignStatus')

<x-layout>
    <x-slot:title>{{ $campaign->name }}</x-slot:title>

    @php
        /*
         * Status first, and nothing above it.
         *
         * A customer opening this page has one question — is this campaign sending,
         * and how far has it got — and it must be answered by the first viewport.
         * Metadata about the template and the transport is below the fold, because
         * that is what a campaign is *about*, not what is happening to it.
         */
        $progress = $summary->progress;
        $nextSend = $operations->nextSendAt();
        $nextAttempt = $operations->nextAttemptAt;
        $lastFailure = $operations->lastFailureSummary();
    @endphp

    <x-page-header :title="$campaign->name" :description="$operations->headline()">
        <x-slot:actions>
            <x-status-badge :status="$campaign->status->tone()" :label="$campaign->status->label()" />

            @include('campaigns.partials.actions', [
                'summary' => $summary,
                'campaign' => $campaign,
            ])

            @if ($campaign->status->allowsStart())
                <form method="POST" action="{{ route('campaigns.start', $campaign) }}">
                    @csrf
                    <x-button type="submit">
                        {{ $campaign->scheduled_at?->isFuture() ? 'Freeze and wait' : 'Start campaign' }}
                    </x-button>
                </form>

                @if ($campaign->scheduled_at?->isFuture())
                    <form method="POST" action="{{ route('campaigns.sendNow', $campaign) }}">
                        @csrf
                        <x-button variant="secondary" type="submit">Send now instead</x-button>
                    </form>
                @endif
            @endif

            <x-button variant="secondary"
                      :href="route('campaigns.show', array_merge([$campaign], request()->query()))">Refresh</x-button>
            <x-button variant="secondary" :href="route('campaigns.index')">All campaigns</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($operations->interruption !== null)
        <x-alert :variant="$campaign->status === CampaignStatus::Paused ? 'warning' : 'danger'"
                 :title="$operations->interruption->headline" class="mb-6">
            <p>{{ $operations->interruption->detail }}</p>
            <p class="mt-2">
                <span class="font-medium">What to do:</span>
                {{ $operations->interruption->nextStep }}
            </p>
        </x-alert>
    @endif

    @if ($campaign->scheduledLocalTime() !== null
            && in_array($campaign->status, [CampaignStatus::Draft, CampaignStatus::Scheduled], true))
        <x-alert variant="info" class="mb-6">
            Starts {{ $campaign->scheduledLocalTime()->format('j M Y \a\t H:i') }}
            ({{ $campaign->scheduledTimezone() }}).
            @if ($campaign->status === CampaignStatus::Scheduled)
                The worker picks it up the next time it runs after that moment, so it can begin later than this
                and never earlier. &ldquo;Send now instead&rdquo; discards the wait, not the checks: the campaign
                still has to pass them.
            @else
                Starting the campaign freezes the message and the audience now and begins at that moment. You can
                still change the time until you do.
            @endif
        </x-alert>
    @endif

    <div class="space-y-6">
        {{-- Progress first, and only where there is one: a draft has no frozen
             audience, so it says so rather than reporting 0% of nothing. --}}
        <x-card>
            {{-- Every line here is the read model's own wording, so the headline,
                 the bar and the figures below cannot disagree with each other or
                 with the campaign list. --}}
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <p class="text-sm font-medium text-slate-900">{{ $progress->label()['title'] }}</p>

                @if ($progress->remaining() > 0)
                    <p class="text-sm text-slate-600">{{ number_format($progress->remaining()) }} still to send</p>
                @endif
            </div>

            <x-progress-bar :progress="$progress" />

            <p class="mt-2 text-xs text-slate-500">{{ $progress->label()['detail'] }}</p>

            <dl class="mt-4 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                @foreach ($recipientStatuses as $recipientStatus)
                    <div class="rounded-md bg-slate-50 px-3 py-2 ring-1 ring-inset ring-slate-200">
                        <dt class="text-xs text-slate-600">{{ $recipientStatus->label() }}</dt>
                        <dd class="mt-0.5 text-lg font-semibold text-slate-900">
                            {{ $summary->frozen ? number_format($progress->count($recipientStatus)) : '—' }}
                        </dd>
                    </div>
                @endforeach
            </dl>

            @if ($summary->frozen)
                <div class="mt-4 grid gap-x-6 gap-y-2 border-t border-slate-200 pt-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <p class="text-xs text-slate-500">Last accepted by a server</p>
                        <p class="mt-0.5 text-slate-800">
                            {{ $operations->lastSuccessAt?->diffForHumans() ?? 'None yet' }}
                        </p>
                    </div>

                    <div>
                        <p class="text-xs text-slate-500">Last refusal</p>
                        <p class="mt-0.5 text-slate-800">
                            @if ($lastFailure['response'] === null)
                                None yet
                            @else
                                <span class="font-mono text-xs">{{ $lastFailure['code'] ?? 'no code' }}</span>
                                <span class="text-xs text-slate-600">{{ $lastFailure['when'] }}</span>
                            @endif
                        </p>
                    </div>

                    <div>
                        <p class="text-xs text-slate-500">Next send may happen</p>
                        <p class="mt-0.5 text-slate-800">
                            @if ($nextSend !== null)
                                {{ $nextSend->format('j M, H:i:s') }}
                                <span class="block text-xs text-slate-500">If the worker runs. A floor, not a promise.</span>
                            @else
                                As soon as the worker runs.
                            @endif
                        </p>
                    </div>

                    <div>
                        <p class="text-xs text-slate-500">Next retry due</p>
                        <p class="mt-0.5 text-slate-800">
                            @if ($nextAttempt !== null)
                                {{ $nextAttempt->format('j M, H:i') }}
                                <span class="block text-xs text-slate-500">
                                    {{ $nextAttempt->isPast() ? 'Due now.' : 'After a temporary failure, not before.' }}
                                </span>
                            @else
                                Nothing is waiting on a timer.
                            @endif
                        </p>
                    </div>
                </div>

                @if ($operations->workerIsActive())
                    <p class="mt-3 text-xs text-slate-600">
                        A worker is processing this campaign right now. This page does not update on its own;
                        refresh to see what it has done.
                    </p>
                @endif
            @endif
        </x-card>

        <div class="grid gap-6 lg:grid-cols-2">
            {{-- The snapshot, in words that say "frozen" rather than "selected",
                 because after the launch none of these are references to live
                 records any more. --}}
            <x-card title="Campaign snapshot"
                    description="What this campaign is actually sending. These are its own copies: editing the template, changing the list or re-verifying the account cannot alter a message already in flight.">
                @if (! $summary->frozen)
                    <x-empty-state
                        title="Nothing frozen yet"
                        description="The message and the audience are copied into this campaign when it starts. Until then, what it points at can still be changed from the campaign form."
                    >
                        <x-slot:actions>
                            <x-button variant="secondary" :href="route('campaigns.edit', $campaign)">Edit the campaign</x-button>
                        </x-slot:actions>
                    </x-empty-state>
                @else
                    <dl class="space-y-4 text-sm">
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-500">Message</dt>
                            <dd class="mt-1 text-slate-900">
                                {{ $operations->templateName() ?? 'A template' }}
                                <span class="text-slate-500">at version {{ $campaign->template_version }}</span>
                            </dd>
                            <dd class="mt-1 text-slate-800">{{ $operations->subject() }}</dd>
                            @if ($campaign->preheader_snapshot)
                                <dd class="mt-0.5 text-xs text-slate-500">{{ $campaign->preheader_snapshot }}</dd>
                            @endif
                        </div>

                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-500">Audience</dt>
                            <dd class="mt-1 text-slate-900">
                                {{ number_format($progress->total()) }} recipients
                                @if ($campaign->list)
                                    <span class="block text-xs text-slate-500">
                                        Copied from &ldquo;{{ $campaign->list->name }}&rdquo; at launch. The list may have
                                        changed since; these {{ number_format($progress->total()) }} did not.
                                    </span>
                                @endif
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-500">Sending account</dt>
                            <dd class="mt-1 text-slate-900">
                                {{ $operations->transport?->label ?? 'The account has been deleted' }}
                            </dd>
                            @if ($operations->transport !== null)
                                <dd class="mt-0.5 text-xs text-slate-500">
                                    {{ $operations->transport->host }}
                                    &middot;
                                    <x-status-badge :status="$operations->transport->effectiveStatus()->tone()"
                                                   :label="$operations->transport->effectiveStatus()->label()" />
                                </dd>
                                {{-- The From identity is shown because it is what recipients
                                     see and is therefore the thing being described. The
                                     username and secret are not, and never will be: they
                                     are credentials, and a campaign page is a screen that
                                     gets left open. --}}
                                <dd class="mt-0.5 text-xs text-slate-500">
                                    From
                                    {{ $operations->transport->from_name }}
                                    &lt;{{ $operations->transport->from_address }}&gt;
                                </dd>
                            @endif
                        </div>

                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-500">Pace</dt>
                            <dd class="mt-1 text-slate-800">
                                At most one message every {{ $preflight->effectiveIntervalSeconds($campaign) }}
                                seconds, {{ $preflight->effectiveBatchSize($campaign) }} per worker run
                            </dd>
                        </div>
                    </dl>
                @endif
            </x-card>

            <div class="space-y-6">
                <x-card title="What this campaign recorded"
                        description="The timestamps this campaign keeps, and the attempts it made. Individual pause and resume cycles are not recorded — a campaign remembers its current state and when it was last active.">
                    @if ($operations->timeline === [])
                        <p class="text-sm text-slate-600">Nothing has happened yet.</p>
                    @else
                        <ol class="space-y-3">
                            @foreach ($operations->timeline as $entry)
                                <li class="flex gap-3">
                                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-slate-300"></span>
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-slate-900">{{ $entry->label }}</p>
                                        <p class="text-xs text-slate-500">
                                            <time datetime="{{ $entry->at->toIso8601String() }}">{{ $entry->at->format('j M Y, H:i') }}</time>
                                            <span class="text-slate-400">&middot;</span>
                                            {{ $entry->at->diffForHumans() }}
                                        </p>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </x-card>

                @if ($report !== null)
                    <x-card title="Checks">
                        <x-alert variant="info" class="mb-4">
                            Answered again now, not from launch. A template can be edited, a transport can expire and
                            a list can change after a campaign started, and none of those changes what this campaign
                            is already sending.
                        </x-alert>

                        @include('campaigns.partials.preflight', ['report' => $report])
                    </x-card>
                @endif
            </div>
        </div>

        {{-- The recipient log. Server-side pagination, because a campaign that has
             run has as many rows here as it had recipients. --}}
        <x-card title="Recipients"
                description="Everyone in the frozen audience, and what happened to them. Skipped means there was nobody to send to; blocked means the platform refused to.">
            @if (! $summary->frozen)
                <x-empty-state
                    title="No recipients yet"
                    description="The audience is copied when this campaign starts. It has not started, so nothing has been frozen."
                />
            @else
                <form method="GET" action="{{ route('campaigns.show', $campaign) }}"
                      class="mb-4 flex flex-wrap items-end gap-3">
                    <div class="min-w-56 flex-1">
                        <x-input-label for="recipient">Search recipient</x-input-label>
                        <x-text-input id="recipient" name="recipient" type="search" class="mt-1 block w-full"
                                      :value="$recipientFilters->search" placeholder="An email address" />
                    </div>

                    <div>
                        <x-input-label for="recipient_status">Status</x-input-label>
                        <select id="recipient_status" name="recipient_status"
                                class="mt-1 block rounded-md border-slate-300 text-sm shadow-sm">
                            <option value="">Any status</option>
                            @foreach ($recipientStatuses as $recipientStatus)
                                @php($optionCount = $recipientStatusCounts[$recipientStatus->value] ?? 0)
                                <option value="{{ $recipientStatus->value }}"
                                        @selected($recipientFilters->status === $recipientStatus)
                                        @disabled($optionCount === 0 && $recipientFilters->status !== $recipientStatus)>
                                    {{ $recipientStatus->label() }} ({{ number_format($optionCount) }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <x-button variant="secondary">Filter</x-button>

                    @if ($recipientFilters->isActive())
                        <a href="{{ route('campaigns.show', $campaign) }}"
                           class="pb-2 text-sm text-slate-600 hover:text-slate-900">Clear</a>
                    @endif
                </form>

                @if ($recipients->isEmpty())
                    <x-empty-state
                        title="No recipients match"
                        description="Nothing in this campaign's frozen audience matches those filters."
                    >
                        <x-slot:actions>
                            <x-button variant="secondary" :href="route('campaigns.show', $campaign)">Clear filters</x-button>
                        </x-slot:actions>
                    </x-empty-state>
                @else
                    <p class="mb-3 text-xs text-slate-500">
                        {{ number_format($recipients->total()) }} recipient{{ $recipients->total() === 1 ? '' : 's' }}
                        in the frozen audience
                        @if ($recipientFilters->isActive())
                            &middot; showing {{ number_format($recipients->count()) }} on this page
                        @endif
                    </p>

                    {{-- Desktop table; the same rows as cards below it on a narrow
                         screen, from the same query, with no JavaScript involved. --}}
                    <div class="hidden overflow-x-auto md:block">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th scope="col" class="py-2 pr-4">Recipient</th>
                                    <th scope="col" class="py-2 pr-4">Status</th>
                                    <th scope="col" class="py-2 pr-4 text-right">Attempts</th>
                                    <th scope="col" class="py-2 pr-4">Last attempt</th>
                                    <th scope="col" class="py-2 pr-4">Result</th>
                                    <th scope="col" class="py-2">Next attempt</th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-slate-100">
                                @foreach ($recipients as $recipient)
                                    @php($result = $operations->resultFor($recipient))
                                    <tr>
                                        <td class="py-2 pr-4">
                                            <span class="font-mono text-xs text-slate-800">{{ $recipient->email }}</span>

                                            @if ($recipient->contact === null)
                                                <span class="block text-xs text-slate-400">
                                                    Contact deleted; the address is the campaign's own copy.
                                                </span>
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4">
                                            <x-status-badge :status="$recipient->status->tone()"
                                                           :label="$recipient->status->label()" />
                                        </td>
                                        <td class="py-2 pr-4 text-right font-mono text-slate-700">
                                            {{ $recipient->attempts }}
                                        </td>
                                        <td class="py-2 pr-4 text-xs text-slate-500">
                                            {{ $recipient->last_attempt_at?->diffForHumans() ?? 'Never' }}
                                        </td>
                                        <td class="py-2 pr-4 text-xs text-slate-600">
                                            @if ($result['message'])
                                                <span class="font-mono">{{ $result['code'] ?? '—' }}</span>
                                                {{ $result['message'] }}
                                            @else
                                                {{ $recipient->status->explanation() }}
                                            @endif
                                        </td>
                                        <td class="py-2 text-xs text-slate-500">
                                            @if ($recipient->next_attempt_at !== null)
                                                {{ $recipient->next_attempt_at->format('j M, H:i') }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>

                                    @if ($recipient->deliveryAttempts->isNotEmpty())
                                        <tr class="bg-slate-50">
                                            <td colspan="6" class="py-2 pr-4">
                                                <details>
                                                    <summary class="cursor-pointer text-xs font-medium text-slate-600">
                                                        {{ $recipient->deliveryAttempts->count() }} attempt(s) recorded
                                                    </summary>

                                                    <ol class="mt-2 space-y-1.5">
                                                        @foreach (CampaignOperations::attemptsFor($recipient) as $attempt)
                                                            <li class="text-xs text-slate-600">
                                                                <span class="font-mono">#{{ $attempt['number'] }}</span>
                                                                <span class="font-medium text-slate-800">{{ $attempt['result'] }}</span>
                                                                @if ($attempt['code'])
                                                                    <span class="font-mono">{{ $attempt['code'] }}</span>
                                                                @endif
                                                                <span class="text-slate-400">{{ $attempt['at'] }}</span>

                                                                @if ($attempt['response'])
                                                                    <p class="mt-0.5 break-words font-mono text-xs text-slate-500">
                                                                        {{ $attempt['response'] }}
                                                                    </p>
                                                                @endif
                                                            </li>
                                                        @endforeach
                                                    </ol>
                                                </details>
                                            </td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="space-y-3 md:hidden">
                        @foreach ($recipients as $recipient)
                            @php($result = $operations->resultFor($recipient))
                            <div class="rounded-md border border-slate-200 p-3">
                                <div class="flex items-start justify-between gap-2">
                                    <span class="min-w-0 break-all font-mono text-xs text-slate-800">{{ $recipient->email }}</span>
                                    <x-status-badge :status="$recipient->status->tone()"
                                                   :label="$recipient->status->label()" />
                                </div>

                                <p class="mt-1 text-xs text-slate-500">{{ $recipient->attempts }} attempt(s) &middot; last {{ $recipient->last_attempt_at?->diffForHumans() ?? 'never attempted' }}</p>

                                <p class="mt-1 text-xs text-slate-600">{{ $result['message'] ?? $recipient->status->explanation() }}</p>

                                @if ($recipient->next_attempt_at !== null)
                                    <p class="mt-1 text-xs text-slate-500">
                                        Next attempt {{ $recipient->next_attempt_at->format('j M, H:i') }}
                                    </p>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-4">{{ $recipients->links() }}</div>
                @endif
            @endif
        </x-card>
    </div>
</x-layout>
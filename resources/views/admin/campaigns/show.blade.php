@use('App\Domain\Campaigns\CampaignRecipientStatus')

<x-layout>
    <x-slot:title>{{ $detail->campaign->name }}</x-slot:title>

    @php
        /*
         * An operator view, not the customer's page again.
         *
         * The differences are all about context: whose campaign this is, which
         * transport it is using, the complete set of lifecycle timestamps, and the
         * owner's other campaigns. The figures underneath are the same read models
         * the customer sees, so an operator and a customer reading the same campaign
         * are never told different things about it.
         */
        $campaign = $detail->campaign;
        $progress = $detail->summary->progress;
        $lastFailure = $detail->operations->lastFailureSummary();
        $nextAttempt = $detail->operations->nextAttemptAt;
    @endphp

    <x-page-header :title="$campaign->name" :description="$detail->operations->headline()">
        <x-slot:actions>
            <x-status-badge :status="$campaign->status->tone()" :label="$campaign->status->label()" />

            @include('admin.campaigns.partials.detail-actions', ['detail' => $detail])

            <x-button variant="secondary"
                      :href="route('admin.campaigns.index', array_merge(request()->query(), []))">All campaigns</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($interruption !== null)
        <x-alert :variant="$campaign->status === \App\Domain\Campaigns\CampaignStatus::Paused ? 'warning' : 'danger'"
                 :title="$interruption->headline" class="mb-6">
            <p>{{ $interruption->detail }}</p>
            <p class="mt-2">
                <span class="font-medium">What to do:</span>
                {{ $interruption->nextStep }}
            </p>
        </x-alert>
    @endif

    <div class="space-y-6">
        <x-card title="Ownership"
                description="Whose campaign this is. Administrators read across tenants here; every change below still goes through the same domain rules the owner's own buttons do.">
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Customer</dt>
                    <dd class="mt-1 text-sm text-slate-900">
                        @if ($detail->owner !== null)
                            <a href="{{ route('admin.users.show', $detail->owner) }}"
                               class="text-indigo-700 hover:underline">{{ $detail->owner->name }}</a>
                        @else
                            <span class="text-slate-500">Deleted account</span>
                        @endif
                    </dd>
                </div>

                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Account email</dt>
                    <dd class="mt-1 font-mono text-xs text-slate-800">{{ $detail->owner?->email ?? '—' }}</dd>
                </div>

                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Created</dt>
                    <dd class="mt-1 text-sm text-slate-800">{{ $campaign->created_at->format('j M Y, H:i') }}</dd>
                </div>

                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Other campaigns by this customer</dt>
                    <dd class="mt-1 text-sm text-slate-800">
                        {{ number_format($detail->otherCampaignsByOwner) }}
                        @if ($detail->owner !== null)
                            <a href="{{ route('admin.campaigns.index', ['owner' => $detail->owner->id]) }}"
                               class="ml-1 text-xs text-indigo-700 hover:underline">See them</a>
                        @endif
                    </dd>
                </div>
            </dl>
        </x-card>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-card title="Sending configuration"
                    description="The transport this campaign froze at launch. No credential is shown here or anywhere in this area.">
                @if ($campaign->smtpAccount === null)
                    <x-empty-state
                        title="No transport"
                        description="The account this campaign was launched with has been deleted. Its sends continue to use whatever was configured, and new sends cannot be resumed from this page."
                    />
                @else
                    <dl class="space-y-3 text-sm">
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-500">Account</dt>
                            <dd class="mt-1 text-slate-900">
                                <a href="{{ route('admin.smtp.accounts.show', $campaign->smtpAccount) }}"
                                   class="text-indigo-700 hover:underline">{{ $campaign->smtpAccount->label }}</a>
                                <span class="block text-xs text-slate-500">{{ number_format($detail->campaignsOnSameTransport) }} campaign{{ $detail->campaignsOnSameTransport === 1 ? '' : 's' }} on the platform use{{ $detail->campaignsOnSameTransport === 1 ? 's' : '' }} this account.</span>
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-500">Hostname</dt>
                            <dd class="mt-1 font-mono text-xs text-slate-800">{{ $campaign->smtpAccount->host }}</dd>
                        </div>

                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-500">From identity</dt>
                            <dd class="mt-1 text-xs text-slate-800">
                                {{ $campaign->smtpAccount->from_name }}
                                &lt;{{ $campaign->smtpAccount->from_address }}&gt;
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-500">Verification</dt>
                            <dd class="mt-1">
                                <x-status-badge :status="$campaign->smtpAccount->effectiveStatus()->tone()"
                                               :label="$campaign->smtpAccount->effectiveStatus()->label()" />
                                <span class="block text-xs text-slate-500">
                                    Read now, not at launch. A verification that lapsed after this campaign started does
                                    not retroactively make its sends invalid, and one that has since lapsed is why a
                                    resume might fail.
                                </span>
                            </dd>
                        </div>
                    </dl>
                @endif
            </x-card>

            <x-card title="Campaign state"
                    description="Every lifecycle timestamp this campaign recorded. There is no event log, so a pause and resume that happened and reversed leaves no trace beyond the last activity.">
                <dl class="grid gap-4 sm:grid-cols-2">
                    @foreach ($detail->lifecycle() as $entry)
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-500">{{ $entry['label'] }}</dt>
                            <dd class="mt-1 text-sm text-slate-800">
                                {{ $entry['at']->format('j M Y, H:i') }}
                                <span class="block text-xs text-slate-500">{{ $entry['at']->diffForHumans() }}</span>
                            </dd>
                        </div>
                    @endforeach

                    @if ($detail->lifecycle() === [])
                        <p class="text-sm text-slate-600">Nothing has happened yet.</p>
                    @endif

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500">Scheduled start</dt>
                        <dd class="mt-1 text-sm text-slate-800">
                            @if ($campaign->scheduledLocalTime() !== null)
                                {{ $campaign->scheduledLocalTime()->format('j M Y, H:i') }}
                                <span class="block text-xs text-slate-500">{{ $campaign->scheduledTimezone() }}</span>
                            @else
                                Not scheduled
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500">Next send may happen</dt>
                        <dd class="mt-1 text-sm text-slate-800">
                            @if ($detail->operations->nextSendAt() !== null)
                                {{ $detail->operations->nextSendAt()->format('j M Y, H:i:s') }}
                                <span class="block text-xs text-slate-500">A floor. The worker still has to be woken.</span>
                            @else
                                As soon as the worker runs.
                            @endif
                        </dd>
                    </div>
                </dl>
            </x-card>
        </div>

        <x-card title="Message frozen at launch"
                description="Copied into the campaign when it started. The template behind it can be edited or deleted without changing any of this.">
            @if (! $detail->summary->frozen)
                <x-empty-state
                    title="Nothing frozen"
                    description="This campaign has not started, so no message or audience has been copied into it. What it points at can still be changed by its owner."
                />
            @else
                <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500">Template</dt>
                        <dd class="mt-1 text-sm text-slate-900">{{ $campaign->template_name_snapshot ?? 'Unknown' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500">Version</dt>
                        <dd class="mt-1 text-sm text-slate-900">{{ $campaign->template_version }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500">Subject</dt>
                        <dd class="mt-1 text-sm text-slate-800">{{ $campaign->subject_snapshot }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500">Preheader</dt>
                        <dd class="mt-1 text-sm text-slate-800">{{ $campaign->preheader_snapshot ?? '—' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs uppercase tracking-wide text-slate-500">Source list at launch</dt>
                        <dd class="mt-1 text-sm text-slate-800">
                            {{ $campaign->list?->name ?? 'Deleted' }}
                            <span class="block text-xs text-slate-500">
                                The audience is these {{ number_format($progress->total()) }} recipients, not the list as it
                                stands now.
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500">Pace</dt>
                        <dd class="mt-1 text-sm text-slate-800">
                            {{ $campaign->rate_interval_seconds }}s between messages,
                            {{ $campaign->worker_batch_size }} per run
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500">Attempts made</dt>
                        <dd class="mt-1 text-sm text-slate-800">{{ number_format($detail->attempts) }}</dd>
                    </div>
                </dl>
            @endif
        </x-card>

        <x-card title="Delivery"
                description="The same counts the customer's page and both campaign lists read, from the same definitions.">
            <x-progress-bar :progress="$progress" />

            <dl class="mt-4 grid gap-3 sm:grid-cols-3 lg:grid-cols-7">
                <div class="rounded-md bg-slate-50 px-3 py-2 ring-1 ring-inset ring-slate-200">
                    <dt class="text-xs text-slate-600">Total</dt>
                    <dd class="mt-0.5 text-lg font-semibold text-slate-900">{{ number_format($progress->total()) }}</dd>
                </div>

                @foreach ($recipientStatuses as $status)
                    <div class="rounded-md bg-slate-50 px-3 py-2 ring-1 ring-inset ring-slate-200">
                        <dt class="text-xs text-slate-600">{{ $status->label() }}</dt>
                        <dd class="mt-0.5 text-lg font-semibold text-slate-900">
                            {{ $detail->summary->frozen ? number_format($progress->count($status)) : '—' }}
                        </dd>
                    </div>
                @endforeach
            </dl>

            <div class="mt-4 grid gap-x-6 gap-y-2 border-t border-slate-200 pt-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <p class="text-xs text-slate-500">Last accepted by a server</p>
                    <p class="mt-0.5 text-slate-800">
                        {{ $detail->operations->lastSuccessAt?->diffForHumans() ?? 'None yet' }}
                    </p>
                </div>

                <div>
                    <p class="text-xs text-slate-500">Last refusal</p>
                    <p class="mt-0.5 text-slate-800">
                        @if ($lastFailure['response'] === null)
                            None yet
                        @else
                            <span class="font-mono text-xs">{{ $lastFailure['code'] ?? 'no code' }}</span>
                            <span class="block text-xs text-slate-600">{{ $lastFailure['when'] }}</span>
                        @endif
                    </p>
                </div>

                <div>
                    <p class="text-xs text-slate-500">Next retry due</p>
                    <p class="mt-0.5 text-slate-800">
                        @if ($nextAttempt !== null)
                            {{ $nextAttempt->format('j M Y, H:i') }}
                        @else
                            Nothing is waiting on a timer.
                        @endif
                    </p>
                </div>

                <div>
                    <p class="text-xs text-slate-500">Recipient log</p>
                    <p class="mt-0.5 text-xs text-slate-600">
                        Not shown here. Every recipient and its attempt history is on the campaign's own page, paged and
                        filterable.
                    </p>
                </div>
            </div>

            @if ($lastFailure['response'] !== null)
                <p class="mt-3 rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600">
                    Most recent server reply, scrubbed for display:
                    <span class="font-mono">{{ $lastFailure['response'] }}</span>
                </p>
            @endif
        </x-card>
    </div>
</x-layout>
@use('App\Domain\Campaigns\CampaignRecipientStatus')
@use('App\Domain\Campaigns\CampaignStatus')

<x-layout>
    <x-slot:title>{{ $campaign->name }}</x-slot:title>

    <x-page-header :title="$campaign->name" :description="$campaign->status->explanation()">
        <x-slot:actions>
            {{-- Pause, resume and cancel come from the same read model the list uses,
                 so this page cannot offer a button the list hides or the platform
                 would refuse. Start and send-now are campaign-page actions: they
                 put messages on the wire, so they are not offered from a list of
                 twenty-five rows. --}}
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

            <x-button variant="secondary" :href="route('campaigns.index')">All campaigns</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($campaign->status === CampaignStatus::Failed && $campaign->failure_reason)
        <x-alert variant="danger" title="This campaign stopped" class="mb-6">
            {{ $campaign->failure_reason }}
        </x-alert>
    @endif

    <div class="space-y-6">
        <div class="flex flex-wrap items-center gap-2">
            <x-status-badge :status="$campaign->status->tone()" :label="$campaign->status->label()" />

            @if ($campaign->hasLaunched())
                <span class="text-sm text-slate-600">
                    Frozen at template version {{ $campaign->template_version }}
                </span>
            @else
                <span class="text-sm text-slate-600">Not started. Nothing has been frozen or sent.</span>
            @endif
        </div>

        @if ($campaign->scheduledLocalTime() !== null)
            <x-alert variant="info" class="mb-6">
                Starts {{ $campaign->scheduledLocalTime()->format('j M Y \a\t H:i') }}
                ({{ $campaign->scheduledTimezone() }}). The worker picks it up the next time it runs after that
                moment, so it can start later than this and never earlier. &ldquo;Send now instead&rdquo; discards
                the wait, not the checks: the campaign still has to pass them.
            </x-alert>
        @endif

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-stat label="Recipients" :value="number_format($counts['total'])"
                    hint="Frozen at launch from the list." />

            <x-stat label="Sent"
                    :value="number_format($counts[CampaignRecipientStatus::Sent->value] ?? 0)"
                    hint="The server accepted these. It does not mean they arrived." />

            <x-stat label="Failed"
                    :value="number_format($counts[CampaignRecipientStatus::Failed->value] ?? 0)"
                    hint="A server refused them, or the transport did." />

            <x-stat label="Remaining" :value="number_format($campaign->remainingCount())"
                    hint="Waiting, or being sent right now." />
        </div>

        @if ($counts['total'] > 0)
            <x-card title="Progress">
                {{-- The same bar the list uses, over the same definition of
                     "dealt with", so the percentage on a row and the percentage
                     here are the same number rather than two calculations that
                     happen to agree today. --}}
                <x-progress-bar :progress="$summary->progress" />

                <p class="mt-2 text-xs text-slate-500">
                    {{ number_format($summary->progress->settled()) }} of {{ number_format($counts['total']) }} recipients have been
                    dealt with: sent, failed, skipped or blocked. Blocked recipients are people who asked not to
                    be contacted before their turn came up, so nothing was sent to them.
                </p>
            </x-card>
        @endif

        @if ($report !== null)
            <x-card title="Checks">
                <x-alert variant="info" class="mb-4">
                    These were answered at launch. They are shown again because a template can be edited, a
                    transport can expire and a list can change after the campaign started, and none of those
                    changes what this campaign is already sending.
                </x-alert>

                @include('campaigns.partials.preflight', ['report' => $report])
            </x-card>
        @endif

        <x-card title="What this campaign is">
            <dl class="grid gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Subject</dt>
                    <dd class="mt-1 text-sm text-slate-800">
                        {{ $campaign->hasLaunched() ? $campaign->subject_snapshot : ($campaign->template?->subject ?? 'Not set') }}
                    </dd>
                </div>

                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Transport</dt>
                    <dd class="mt-1 text-sm text-slate-800">
                        {{ $campaign->smtpAccount?->label ?? 'Not set' }}
                        @if ($campaign->smtpAccount)
                            <span class="block text-xs text-slate-500">{{ $campaign->smtpAccount->host }}</span>
                        @endif
                    </dd>
                </div>

                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Audience</dt>
                    <dd class="mt-1 text-sm text-slate-800">
                        {{ $campaign->list?->name ?? 'Not set' }}
                        <span class="block text-xs text-slate-500">
                            Frozen at launch. Changing the list now does not change this campaign.
                        </span>
                    </dd>
                </div>

                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Minimum send interval</dt>
                    <dd class="mt-1 text-sm text-slate-800">
                        {{ $preflight->effectiveIntervalSeconds($campaign) }} seconds
                        <span class="block text-xs text-slate-500">
                            A minimum gap between messages, not a schedule. Your hosting scheduler decides when
                            the worker runs, so the real cadence is whatever it can manage.
                        </span>
                    </dd>
                </div>

                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Messages per worker run</dt>
                    <dd class="mt-1 text-sm text-slate-800">{{ $preflight->effectiveBatchSize($campaign) }}</dd>
                </div>

                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Started</dt>
                    <dd class="mt-1 text-sm text-slate-800">
                        {{ $campaign->started_at?->format('j M Y, H:i') ?? 'Not started' }}
                    </dd>
                </div>

                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Next send may happen</dt>
                    <dd class="mt-1 text-sm text-slate-800">
                        @if ($campaign->next_send_at && $campaign->next_send_at->isFuture())
                            {{ $campaign->next_send_at->format('j M Y, H:i') }}
                            <span class="block text-xs text-slate-500">
                                Then, if the worker runs. A delayed job is a floor, not a promise.
                            </span>
                        @else
                            As soon as the worker runs.
                        @endif
                    </dd>
                </div>

                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Last activity</dt>
                    <dd class="mt-1 text-sm text-slate-800">
                        {{ $campaign->last_activity_at?->diffForHumans() ?? 'Nothing has happened yet' }}
                    </dd>
                </div>
            </dl>

            @if ($lastFailure)
                <div class="mt-4 rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-900 ring-1 ring-inset ring-rose-200">
                    <p class="font-medium">Most recent refusal</p>
                    <p class="mt-0.5">
                        {{ $lastFailure->contact?->email ?? 'A recipient' }}
                        @if ($lastFailure->last_error_code)
                            ({{ $lastFailure->last_error_code }})
                        @endif
                        &mdash; {{ $lastFailure->last_error_message }}
                    </p>
                </div>
            @endif
        </x-card>

        @if ($campaign->hasLaunched())
            <x-card title="Recipients"
                    description="Every person in the frozen audience, and what has happened to them. Skipped means there was nobody to send to; blocked means the platform refused to.">
                @if ($recipients->isEmpty())
                    <x-empty-state
                        title="No recipients yet"
                        description="The audience snapshot is taken when the campaign starts. This campaign has not started, so there is nothing frozen."
                    />
                @else
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="py-2 pr-4">Recipient</th>
                                <th class="py-2 pr-4">Status</th>
                                <th class="py-2 pr-4">Attempts</th>
                                <th class="py-2 pr-4">Last attempted</th>
                                <th class="py-2">Reason</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($recipients as $recipient)
                                <tr>
                                    <td class="py-2 pr-4 font-mono text-xs text-slate-800">
                                        {{ $recipient->contact?->email ?? 'Contact deleted' }}
                                    </td>
                                    <td class="py-2 pr-4">
                                        <x-status-badge :status="$recipient->status->tone()" :label="$recipient->status->label()" />
                                    </td>
                                    <td class="py-2 pr-4 font-mono text-slate-700">{{ $recipient->attempts }}</td>
                                    <td class="py-2 pr-4 text-xs text-slate-500">
                                        {{ $recipient->last_attempt_at?->diffForHumans() ?? 'Never' }}
                                    </td>
                                    <td class="py-2 text-xs text-slate-600">
                                        {{ $recipient->last_error_message ?? '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div class="mt-4">{{ $recipients->links() }}</div>
                @endif
            </x-card>
        @endif
    </div>
</x-layout>
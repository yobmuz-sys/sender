@use('App\Domain\Campaigns\CampaignRecipientStatus')
@use('App\Domain\Campaigns\Operator\CampaignOperatorFilters')
@use('App\Domain\Campaigns\CampaignStatus')

<x-layout>
    <x-slot:title>Campaigns</x-slot:title>

    <x-page-header
        title="Campaigns"
        description="Monitor campaign activity across customer accounts."
    >
        <x-slot:actions>
            <x-button variant="secondary" :href="route('admin.campaigns.index', request()->query())">Refresh</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- Platform-wide figures, counted from the campaigns table rather than from
         any job or queue state. Each tile narrows the list below it to that state,
         and the counts ignore the filters: a header that changed when you searched
         would be answering a different question than it looks like it is. --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
        @foreach ($statuses as $status)
            @php($count = $statusCounts[$status->value] ?? 0)
            <a href="{{ route('admin.campaigns.index', array_merge(request()->except('status'), ['status' => $status->value])) }}"
               @class([
                   'rounded-lg border bg-white px-4 py-3 shadow-sm transition',
                   'border-slate-900 ring-1 ring-slate-900' => $filters->status === $status,
                   'border-slate-200 hover:border-slate-400' => $filters->status !== $status,
               ])
               @if ($filters->status === $status) aria-current="page" @endif>
                <p class="text-xs uppercase tracking-wide text-slate-500">{{ $status->label() }}</p>
                <p class="mt-1 text-xl font-semibold text-slate-900">{{ number_format($count) }}</p>
            </a>
        @endforeach
    </div>

    <p class="mb-6 text-sm text-slate-600">
        @if ($sendingNow > 0)
            <span class="font-medium text-slate-900">{{ number_format($sendingNow) }} campaign{{ $sendingNow === 1 ? '' : 's' }} are currently sending</span>,
            which is the number whose own state says {{ $sendingNow === 1 ? 'it is' : 'they are' }} running.
        @else
            Nothing is sending anywhere on the platform right now.
        @endif
    </p>

    {{--
        Incidents first.

        A stopped campaign is the thing an operator opens this page to find, and
        finding it should not depend on noticing a filter. The reason shown is the
        one recorded when the campaign stopped, verbatim: an administrator reading
        "the transport stopped accepting mail" must be reading the platform's own
        account of the failure, not a summary written for a different audience. No
        remedy is offered here, and none of these rows can be made to continue by
        switching transport — that is the failure the failed state exists to prevent.
    --}}
    @if ($incidentCount > 0)
        <x-card class="mb-6" title="{{ $incidentCount }} campaign{{ $incidentCount === 1 ? '' : 's' }} need{{ $incidentCount === 1 ? 's' : '' }} attention"
                description="Paused or stopped. Every reason below was recorded by the campaign when it stopped.">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="py-2 pr-4">Campaign</th>
                            <th scope="col" class="py-2 pr-4">Owner</th>
                            <th scope="col" class="py-2 pr-4">Status</th>
                            <th scope="col" class="py-2 pr-4">Problem</th>
                            <th scope="col" class="py-2 pr-4">Transport</th>
                            <th scope="col" class="py-2 pr-4">Last activity</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-100">
                        @foreach ($incidents as $incident)
                            <tr>
                                <td class="py-2 pr-4">
                                    <a href="{{ route('admin.campaigns.show', $incident->campaign) }}"
                                       class="font-medium text-indigo-700 hover:underline">
                                        {{ $incident->campaign->name }}
                                    </a>
                                </td>
                                <td class="py-2 pr-4 text-xs text-slate-600">
                                    {{ $incident->ownerName }}
                                    <span class="block text-slate-400">{{ $incident->ownerEmail }}</span>
                                </td>
                                <td class="py-2 pr-4">
                                    <x-status-badge :status="$incident->campaign->status->tone()"
                                                   :label="$incident->campaign->status->label()" />
                                </td>
                                <td class="max-w-md py-2 pr-4 text-xs text-slate-600">
                                    {{ $incident->problem() }}
                                </td>
                                <td class="py-2 pr-4 text-xs text-slate-600">
                                    @if ($incident->transport !== null)
                                        <a href="{{ route('admin.smtp.accounts.show', $incident->transport) }}"
                                           class="text-indigo-700 hover:underline">{{ $incident->transport->label }}</a>
                                        <span class="block text-slate-400">{{ $incident->transport->host }}</span>
                                    @else
                                        <span class="text-slate-400">Account deleted</span>
                                    @endif
                                </td>
                                <td class="py-2 text-xs text-slate-500">
                                    {{ $incident->lastActivityAt()?->diffForHumans() ?? 'Never' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($incidentCount > $incidents->count())
                <p class="mt-3 text-xs text-slate-500">
                    Showing the {{ $incidents->count() }} most recent of {{ number_format($incidentCount) }}. Every one
                    is reachable from the list below.
                </p>
            @endif
        </x-card>
    @endif

    <x-card class="mb-6">
        <form method="GET" action="{{ route('admin.campaigns.index') }}" class="flex flex-wrap items-end gap-3">
            <div class="min-w-52 flex-1">
                <x-input-label for="search">Search campaign</x-input-label>
                <x-text-input id="search" name="search" type="search" class="mt-1 block w-full"
                              :value="$filters->search" placeholder="Name or frozen subject" />
            </div>

            <div class="min-w-48">
                <x-input-label for="owner_search">Customer</x-input-label>
                <x-text-input id="owner_search" name="owner_search" type="search" class="mt-1 block w-full"
                              :value="$filters->owner" placeholder="Name or email" />
            </div>

            <div>
                <x-input-label for="status">Status</x-input-label>
                <select id="status" name="status"
                        class="mt-1 block rounded-md border-slate-300 text-sm shadow-sm">
                    <option value="">Any status</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}" @selected($filters->status === $status)>
                            {{ $status->label() }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="owner">Owned by</x-input-label>
                <select id="owner" name="owner"
                        class="mt-1 block max-w-64 rounded-md border-slate-300 text-sm shadow-sm">
                    <option value="">Any customer</option>
                    @foreach ($owners as $owner)
                        <option value="{{ $owner->id }}" @selected($filters->ownerId === $owner->id)>
                            {{ $owner->name }} ({{ number_format($owner->campaigns_count) }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="smtp_account">Transport</x-input-label>
                <select id="smtp_account" name="smtp_account"
                        class="mt-1 block max-w-56 rounded-md border-slate-300 text-sm shadow-sm">
                    <option value="">Any account</option>
                    @foreach ($transports as $transport)
                        <option value="{{ $transport->id }}" @selected($filters->smtpAccountId === $transport->id)>
                            {{ $transport->label }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="activity">Activity</x-input-label>
                <select id="activity" name="activity"
                        class="mt-1 block rounded-md border-slate-300 text-sm shadow-sm">
                    <option value="">Any activity</option>
                    @foreach ($activities as $activity)
                        <option value="{{ $activity }}" @selected($filters->activity === $activity)>
                            {{ \App\Domain\Campaigns\Operator\CampaignOperatorFilters::activityLabel($activity) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <x-button variant="secondary">Filter</x-button>

            @if ($filters->isActive())
                <a href="{{ route('admin.campaigns.index') }}" class="pb-2 text-sm text-slate-600 hover:text-slate-900">Clear</a>
            @endif
        </form>
    </x-card>

    @if ($campaigns->total() === 0 && $filters->isActive())
        <x-empty-state
            title="No campaigns match"
            description="Nothing on the platform fits those filters. Clearing them shows every campaign."
        >
            <x-slot:actions>
                <x-button variant="secondary" :href="route('admin.campaigns.index')">Clear filters</x-button>
            </x-slot:actions>
        </x-empty-state>
    @elseif ($campaigns->isEmpty())
        <x-empty-state
            title="No campaigns currently exist."
            description="Campaigns appear here as soon as a customer launches one. Nothing on this page creates, starts or configures a campaign: those are the customer's decisions, made with a preflight."
        />
    @else
        @if ($filters->isActive())
            <p class="mb-3 text-sm text-slate-600">
                {{ number_format($campaigns->total()) }} campaign{{ $campaigns->total() === 1 ? '' : 's' }} match
                these filters, out of {{ number_format(array_sum($statusCounts)) }} in total.
            </p>
        @endif

        {{-- The same rows twice: a table where the columns fit, cards where they do
             not. One query, one set of figures, no client-side switching. --}}
        <div class="hidden overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm lg:block">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-5 py-3">Campaign</th>
                        <th scope="col" class="px-5 py-3">Owner</th>
                        <th scope="col" class="px-5 py-3">Status</th>
                        <th scope="col" class="px-5 py-3">Transport</th>
                        <th scope="col" class="px-5 py-3 text-right">Recipients</th>
                        <th scope="col" class="px-5 py-3 text-right">Sent</th>
                        <th scope="col" class="px-5 py-3 text-right">Failed</th>
                        <th scope="col" class="px-5 py-3 text-right">Remaining</th>
                        <th scope="col" class="px-5 py-3">Last activity</th>
                        <th scope="col" class="px-5 py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-100">
                    @foreach ($campaigns as $row)
                        <tr>
                            <td class="px-5 py-3">
                                <a href="{{ route('admin.campaigns.show', $row->campaign) }}"
                                   class="font-medium text-indigo-700 hover:underline">{{ $row->campaign->name }}</a>
                                <span class="block text-xs text-slate-500">
                                    {{ $row->summary->audienceCount() === null
                                        ? 'Not launched'
                                        : $row->campaign->subject_snapshot }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-xs text-slate-600">
                                {{ $row->ownerName }}
                                <span class="block text-slate-400">{{ $row->ownerEmail }}</span>
                            </td>
                            <td class="px-5 py-3">
                                <x-status-badge :status="$row->campaign->status->tone()"
                                               :label="$row->campaign->status->label()" />
                                @if ($row->isStalled())
                                    <span class="mt-1 block text-xs text-amber-700">No activity recently</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-xs text-slate-600">
                                @if ($row->transport !== null)
                                    {{ $row->transport->label }}
                                    <span class="block text-slate-400">{{ $row->transport->host }}</span>
                                @else
                                    <span class="text-slate-400">Not set</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right font-mono text-xs text-slate-700">
                                {{ $row->summary->audienceCount() === null ? '—' : number_format($row->summary->audienceCount()) }}
                            </td>
                            <td class="px-5 py-3 text-right font-mono text-xs text-slate-700">
                                {{ number_format($row->summary->sentCount()) }}
                            </td>
                            <td class="px-5 py-3 text-right font-mono text-xs">
                                @php($failed = $row->progress()->count(CampaignRecipientStatus::Failed))
                                <span @class(['font-mono text-xs', 'text-rose-700' => $failed > 0, 'text-slate-700' => $failed === 0])>
                                    {{ number_format($failed) }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-right font-mono text-xs text-slate-700">
                                {{ number_format($row->progress()->remaining()) }}
                            </td>
                            <td class="px-5 py-3 text-xs text-slate-500">
                                {{ $row->lastActivityAt()?->diffForHumans() ?? 'Never' }}
                            </td>
                            <td class="px-5 py-3">
                                @include('admin.campaigns.partials.actions', ['summary' => $row])
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="space-y-3 lg:hidden">
            @foreach ($campaigns as $row)
                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <a href="{{ route('admin.campaigns.show', $row->campaign) }}"
                               class="font-medium text-indigo-700 hover:underline">{{ $row->campaign->name }}</a>
                            <p class="text-xs text-slate-500">{{ $row->ownerName }} &middot; {{ $row->ownerEmail }}</p>
                        </div>

                        <x-status-badge :status="$row->campaign->status->tone()"
                                       :label="$row->campaign->status->label()" />
                    </div>

                    <dl class="mt-3 grid grid-cols-3 gap-2 text-center">
                        <div class="rounded-md bg-slate-50 px-2 py-1.5">
                            <dt class="text-xs text-slate-500">Recipients</dt>
                            <dd class="font-mono text-sm">{{ $row->summary->audienceCount() === null ? '—' : number_format($row->summary->audienceCount()) }}</dd>
                        </div>
                        <div class="rounded-md bg-slate-50 px-2 py-1.5">
                            <dt class="text-xs text-slate-500">Sent</dt>
                            <dd class="font-mono text-sm">{{ number_format($row->summary->sentCount()) }}</dd>
                        </div>
                        <div class="rounded-md bg-slate-50 px-2 py-1.5">
                            <dt class="text-xs text-slate-500">Remaining</dt>
                            <dd class="font-mono text-sm">{{ number_format($row->progress()->remaining()) }}</dd>
                        </div>
                    </dl>

                    <p class="mt-2 text-xs text-slate-500">
                        {{ $row->transport?->label ?? 'No transport' }}
                        &middot;
                        {{ $row->lastActivityAt()?->diffForHumans() ?? 'no activity yet' }}
                    </p>

                    @if ($row->isStalled())
                        <p class="mt-1 text-xs text-amber-700">No activity recently, although its status says running.</p>
                    @endif

                    <div class="mt-3">
                        @include('admin.campaigns.partials.actions', ['summary' => $row])
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-4">{{ $campaigns->links() }}</div>
    @endif
</x-layout>

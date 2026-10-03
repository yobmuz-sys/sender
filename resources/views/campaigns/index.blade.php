@use('App\Domain\Campaigns\CampaignRecipientStatus')
@use('App\Domain\Campaigns\CampaignStatus')

<x-layout>
    <x-slot:title>Campaigns</x-slot:title>

    <x-page-header
        title="Campaigns"
        description="Create, watch and stop your campaigns. Every figure below is counted from the messages this platform has actually submitted."
    >
        <x-slot:actions>
            <x-button variant="secondary" :href="route('campaigns.index', request()->query())">Refresh</x-button>
            <x-button :href="route('campaigns.create')">New campaign</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- One figure per state, each a link to the same list narrowed to it. The
         counts deliberately ignore the filters below: a header that changed when
         you searched would be reporting a different question than the one it looks
         like it is answering. --}}
    <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
        @foreach ($statuses as $status)
            @php($count = $statusCounts[$status->value] ?? 0)
            <a href="{{ route('campaigns.index', array_merge(request()->query(), ['status' => $status->value, 'page' => null])) }}"
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

    <x-card class="mb-6">
        <form method="GET" action="{{ route('campaigns.index') }}" class="flex flex-wrap items-end gap-3">
            <div class="min-w-56 flex-1">
                <x-input-label for="search" value="Search" />
                <x-text-input id="search" name="search" type="search" class="mt-1 block w-full"
                              :value="$filters->search" placeholder="Campaign name or subject" />
            </div>

            <div>
                <x-input-label for="status" value="Status" />
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
                <x-input-label for="smtp_account_id" value="Sending account" />
                <select id="smtp_account_id" name="smtp_account_id"
                        class="mt-1 block rounded-md border-slate-300 text-sm shadow-sm">
                    <option value="">Any account</option>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}" @selected($filters->accountId === $account->id)>
                            {{ $account->label }}
                        </option>
                    @endforeach
                </select>
            </div>

            <x-button variant="secondary">Filter</x-button>

            @if ($filters->isActive())
                <a href="{{ route('campaigns.index') }}" class="pb-2 text-sm text-slate-600 hover:text-slate-900">Clear</a>
            @endif
        </form>
    </x-card>

    @if ($campaigns->total() === 0 && $filters->isActive())
        <x-empty-state
            title="No campaigns match"
            description="Nothing here fits those filters. Clearing them shows all of your campaigns."
        >
            <x-slot:actions>
                <x-button variant="secondary" :href="route('campaigns.index')">Clear filters</x-button>
            </x-slot:actions>
        </x-empty-state>
    @elseif ($campaigns->isEmpty())
        <x-empty-state
            title="No campaigns yet"
            description="Create a campaign when you have a ready template, a verified sending account and an audience you have already checked. Nothing is sent until you start it."
        >
            <x-slot:actions>
                <x-button :href="route('campaigns.create')">New campaign</x-button>
            </x-slot:actions>
        </x-empty-state>
    @else
        @if ($filters->isActive())
            <p class="mb-3 text-sm text-slate-600">
                {{ number_format($campaigns->total()) }} campaign{{ $campaigns->total() === 1 ? '' : 's' }} match
                these filters, out of {{ number_format(array_sum($statusCounts)) }} in total.
            </p>
        @endif

        {{-- Two layouts of one dataset: a table where there is room for columns, and
             a card per campaign where there is not. Same figures, same actions, no
             JavaScript deciding which one a screen gets. --}}
        <div class="hidden overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm md:block">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-5 py-3">Campaign</th>
                        <th scope="col" class="px-5 py-3">Status</th>
                        <th scope="col" class="px-5 py-3 text-right">Audience</th>
                        <th scope="col" class="px-5 py-3 text-right">Sent</th>
                        <th scope="col" class="px-5 py-3 text-right">Remaining</th>
                        <th scope="col" class="px-5 py-3">Last activity</th>
                        <th scope="col" class="px-5 py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-100">
                    @foreach ($campaigns as $summary)
                        @php($campaign = $summary->campaign)
                        <tr class="hover:bg-slate-50">
                            <td class="px-5 py-4">
                                <a href="{{ route('campaigns.show', $campaign) }}"
                                   class="font-medium text-slate-900 hover:underline">
                                    {{ $campaign->name }}
                                </a>

                                <p class="mt-0.5 text-xs text-slate-500">
                                    @if ($campaign->list)
                                        {{ $campaign->list->name }}
                                    @else
                                        No list
                                    @endif

                                    @if ($campaign->smtpAccount)
                                        <span class="text-slate-400">/</span> {{ $campaign->smtpAccount->label }}
                                    @endif
                                </p>

                                <x-progress-bar :progress="$summary->progress" />
                            </td>

                            <td class="px-5 py-4 align-top">
                                <x-status-badge :status="$summary->status()->tone()" :label="$summary->status()->label()" />
                            </td>

                            <td class="px-5 py-4 text-right align-top tabular-nums text-slate-700">
                                {{ $summary->audienceCount() === null ? '—' : number_format($summary->audienceCount()) }}
                            </td>

                            <td class="px-5 py-4 text-right align-top tabular-nums text-slate-700">
                                {{ $summary->frozen ? number_format($summary->sentCount()) : '—' }}
                            </td>

                            <td class="px-5 py-4 text-right align-top tabular-nums text-slate-700">
                                @if ($summary->frozen)
                                    {{ number_format($summary->progress->remaining()) }}

                                    @if ($summary->progress->count(CampaignRecipientStatus::Failed) > 0)
                                        <p class="text-xs text-rose-700">
                                            {{ number_format($summary->progress->count(CampaignRecipientStatus::Failed)) }} failed
                                        </p>
                                    @endif
                                @else
                                    —
                                @endif
                            </td>

                            <td class="px-5 py-4 align-top text-slate-600">
                                {{ $summary->lastActivity() ?? 'never started' }}
                            </td>

                            <td class="px-5 py-4 align-top">
                                @include('campaigns.partials.actions', ['summary' => $summary, 'campaign' => $campaign])
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="space-y-3 md:hidden">
            @foreach ($campaigns as $summary)
                @php($campaign = $summary->campaign)
                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="flex items-start justify-between gap-3">
                        <a href="{{ route('campaigns.show', $campaign) }}"
                           class="min-w-0 font-medium text-slate-900 hover:underline">
                            {{ $campaign->name }}
                        </a>

                        <x-status-badge :status="$summary->status()->tone()" :label="$summary->status()->label()" />
                    </div>

                    <p class="mt-1 text-xs text-slate-500">
                        @if ($campaign->list)
                            {{ $campaign->list->name }}
                        @endif

                        @if ($campaign->smtpAccount)
                            <span class="text-slate-400">/</span> {{ $campaign->smtpAccount->label }}
                        @endif
                    </p>

                    <x-progress-bar :progress="$summary->progress" />

                    <dl class="mt-3 grid grid-cols-3 gap-2 text-sm">
                        <div>
                            <dt class="text-xs text-slate-500">Audience</dt>
                            <dd class="tabular-nums text-slate-900">
                                {{ $summary->audienceCount() === null ? '—' : number_format($summary->audienceCount()) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">Sent</dt>
                            <dd class="tabular-nums text-slate-900">
                                {{ $summary->frozen ? number_format($summary->sentCount()) : '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">Remaining</dt>
                            <dd class="tabular-nums text-slate-900">
                                @if ($summary->frozen)
                                    {{ number_format($summary->progress->remaining()) }}
                                @else
                                    —
                                @endif
                            </dd>
                        </div>
                    </dl>

                    <p class="mt-2 text-xs text-slate-500">{{ $summary->lastActivity() ?? 'never started' }}</p>

                    <div class="mt-3 border-t border-slate-100 pt-3">
                        @include('campaigns.partials.actions', ['summary' => $summary, 'campaign' => $campaign])
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-4">{{ $campaigns->links() }}</div>
    @endif
</x-layout>
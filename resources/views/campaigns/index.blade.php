<x-layout>
    <x-slot:title>Campaigns</x-slot:title>

    <x-page-header
        title="Campaigns"
        description="A prepared message, its frozen copy of an audience, and what has happened to each of them. Every figure below is counted from what the platform has actually done."
    >
        <x-slot:actions>
            <x-button :href="route('campaigns.create')">New campaign</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($campaigns->isEmpty())
        <x-empty-state
            title="No campaigns yet"
            description="Create a campaign when you are ready to send a prepared message to an audience you have already checked. Nothing is sent until you start it."
        >
            <x-slot:actions>
                <x-button :href="route('campaigns.create')">New campaign</x-button>
            </x-slot:actions>
        </x-empty-state>
    @else
        <div class="space-y-4">
            @foreach ($campaigns as $campaign)
                <x-card>
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <h2 class="text-sm font-semibold text-slate-900">
                                <a href="{{ route('campaigns.show', $campaign) }}" class="hover:underline">
                                    {{ $campaign->name }}
                                </a>
                            </h2>

                            <p class="mt-1 text-xs text-slate-500">
                                @if ($campaign->hasLaunched())
                                    Subject &ldquo;{{ $campaign->subject_snapshot }}&rdquo;
                                    @if ($campaign->template_version)
                                        <span>at template version {{ $campaign->template_version }}</span>
                                    @endif
                                @elseif ($campaign->template)
                                    Template: {{ $campaign->template->name }}
                                @endif
                            </p>

                            <p class="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                <x-status-badge :status="$campaign->status->tone()" :label="$campaign->status->label()" />

                                <span>{{ number_format($campaign->recipients_count) }} recipients</span>

                                @if ($campaign->list)
                                    <span>on {{ $campaign->list->name }}</span>
                                @endif

                                @if ($campaign->last_activity_at)
                                    <span>last activity {{ $campaign->last_activity_at->diffForHumans() }}</span>
                                @elseif ($campaign->scheduledLocalTime() !== null)
                                    <span>starts {{ $campaign->scheduledLocalTime()->format('j M \a\t H:i') }} ({{ $campaign->scheduledTimezone() }})</span>
                                @else
                                    <span>never started</span>
                                @endif
                            </p>
                        </div>

                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            @if ($campaign->status->allowsConfigurationEdit())
                                <x-button variant="secondary" :href="route('campaigns.edit', $campaign)">Edit</x-button>
                            @endif

                            <x-button :href="route('campaigns.show', $campaign)">Open</x-button>
                        </div>
                    </div>
                </x-card>
            @endforeach
        </div>

        <div class="mt-4">{{ $campaigns->links() }}</div>
    @endif
</x-layout>
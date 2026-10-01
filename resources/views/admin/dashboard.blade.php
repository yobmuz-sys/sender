<x-layout>
    <x-slot:title>Administration</x-slot:title>

    <x-page-header
        title="Administration"
        :description="'Signed in as '.auth()->user()->role->label().'. This overview reports only what the platform can currently measure.'"
    >
        <x-slot:actions>
            @can('users.create')
                <a href="{{ route('admin.users.create') }}"
                   class="inline-flex items-center rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">
                    Add user
                </a>
            @endcan
            @can(\App\Domain\Users\Permission::SYSTEM_VIEW)
                <a href="{{ route('admin.system.index') }}"
                   class="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    System
                </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @unless ($overall === \App\Domain\System\Enums\CapabilityStatus::Ready)
        <x-alert variant="warning" title="The installation is not fully ready" class="mb-6">
            Overall capability is <strong>{{ $overall->value }}</strong>.
            <a href="{{ route('admin.system.index') }}" class="font-medium underline">Review the system overview</a>
            for what is holding it back.
        </x-alert>
    @endunless

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="Overall capability" :value="$overall->value" />
        <x-stat label="Accounts" :value="$userTotal" :hint="$staffTotal.' staff'" />
        <x-stat label="Suspended" :value="$suspendedTotal" />
        <x-stat label="Recent failures" :value="$failedRunCount" hint="last 10 recorded runs" />
    </div>

    <div class="mt-8 grid gap-6 lg:grid-cols-2">
        <x-card title="Capabilities" description="Measured by the shared registry.">
            <ul class="divide-y divide-slate-100">
                @foreach ($subjects as $name => $status)
                    <li class="flex items-center justify-between gap-3 py-2">
                        <a href="{{ route('admin.system.index') }}" class="text-sm capitalize text-slate-700 hover:text-slate-900">
                            {{ str_replace('_', ' ', $name) }}
                        </a>
                        <x-status-badge :status="$status->value" />
                    </li>
                @endforeach
            </ul>
        </x-card>

        <x-card title="Scheduled runs" description="Durable evidence, not a cache entry.">
            @if ($recentRuns->isEmpty())
                <x-empty-state
                    title="No runs recorded yet"
                    description="Add the sender:heartbeat cron entry and the platform will record evidence from then on."
                />
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($recentRuns as $run)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <span class="text-sm text-slate-600">
                                {{ $run->started_at->diffForHumans() }}
                                @if ($run->duration_ms !== null)
                                    <span class="text-slate-400">· {{ $run->duration_ms }}ms</span>
                                @endif
                            </span>
                            <x-status-badge :status="$run->status->value" :label="$run->status->label()" />
                        </li>
                    @endforeach
                </ul>

                <a href="{{ route('admin.runs.index') }}" class="mt-4 inline-block text-sm font-medium text-slate-700 underline">
                    View all runs
                </a>
            @endif
        </x-card>

        <x-card title="Newest accounts">
            @if ($recentUsers->isEmpty())
                <x-empty-state title="No accounts yet" />
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($recentUsers as $account)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <a href="{{ route('admin.users.show', $account) }}" class="text-sm text-slate-700 hover:text-slate-900">
                                {{ $account->name }}
                            </a>
                            <span class="flex items-center gap-2">
                                <x-status-badge status="{{ $account->status->value }}" :label="$account->status->label()" />
                                <span class="text-xs text-slate-500">{{ $account->role->label() }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>

        <x-card title="Availability">
            <ul class="space-y-2">
                @foreach ($availability as $operation => $decision)
                    <li class="flex items-center justify-between gap-3 text-sm">
                        <span class="text-slate-700">{{ \Illuminate\Support\Str::headline(str_replace('.', ' ', $operation)) }}</span>
                        <x-status-badge :status="$decision->capability->value" />
                    </li>
                @endforeach
            </ul>
        </x-card>
    </div>
</x-layout>

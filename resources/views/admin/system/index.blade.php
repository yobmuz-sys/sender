<x-layout>
    <x-slot:title>System</x-slot:title>

    <x-page-header
        title="System overview"
        description="Host capability, operator switches and configuration this installation is running."
    />

    <div class="grid gap-4 sm:grid-cols-4">
        <x-stat label="Overall" :value="$overall->value" />
        <x-stat label="Environment" :value="$environment" hint="staff only" />
        <x-stat label="Queue driver" :value="$queueDriver" />
        <x-stat label="Detailed diagnostics"
                :value="$diagnosticsEnabled ? 'enabled' : 'disabled'" />
    </div>

    @unless ($diagnosticsEnabled)
        <x-alert variant="warning" title="Detailed diagnostics are disabled" class="mt-6">
            The detailed report is switched off by
            <code class="font-mono text-xs">SENDER_DIAGNOSTICS_ENABLED</code>. This overview remains
            available and shows everything needed to act.
        </x-alert>
    @endunless

    <div class="mt-8 grid gap-6 lg:grid-cols-2">
        <x-card title="Capabilities">
            <ul class="divide-y divide-slate-100">
                @foreach ($subjects as $name => $status)
                    <li class="flex items-center justify-between gap-3 py-2">
                        <span class="text-sm capitalize text-slate-700">{{ str_replace('_', ' ', $name) }}</span>
                        <x-status-badge :status="$status->value" />
                    </li>
                @endforeach
            </ul>
        </x-card>

        <x-card title="Host checks" description="Everything the inspector measured.">
            <ul class="divide-y divide-slate-100">
                @foreach ($report->checks as $check)
                    <li class="flex items-start justify-between gap-3 py-2">
                        <div>
                            <p class="text-sm text-slate-700">{{ $check->name }}</p>
                            <p class="mt-0.5 text-xs text-slate-500">{{ $check->detail }}</p>
                        </div>
                        <x-status-badge :status="$check->capability->value" />
                    </li>
                @endforeach
            </ul>
        </x-card>

        <x-card title="Operator switches" description="Persisted in system_settings.">
            <ul class="space-y-2">
                @foreach ($flags as $name => $enabled)
                    <li class="flex items-center justify-between gap-3 text-sm">
                        <span class="capitalize text-slate-700">{{ str_replace('_', ' ', $name) }}</span>
                        <x-status-badge :status="$enabled ? 'active' : 'failed'"
                                       :label="$enabled ? 'Enabled' : 'Disabled'" />
                    </li>
                @endforeach
            </ul>

            <a href="{{ route('admin.system.subsystems') }}" class="mt-4 inline-block text-sm font-medium text-slate-700 underline">
                Manage subsystems
            </a>
        </x-card>

        <x-card title="Queue safety">
            <p class="text-sm text-slate-700">
                Reservation window
                <span class="font-mono">{{ $retryAfter }}s</span> against a permitted runtime of
                <span class="font-mono">{{ $maxRuntime }}s</span>.
            </p>
            <p class="mt-2 text-xs text-slate-500">
                A window shorter than the runtime lets two workers process the same job.
            </p>
        </x-card>
    </div>

    <div class="mt-6 flex flex-wrap gap-3">
        <a href="{{ route('admin.system.diagnostics') }}" class="text-sm font-medium text-slate-700 underline">Diagnostics</a>
        <a href="{{ route('admin.system.subsystems') }}" class="text-sm font-medium text-slate-700 underline">Subsystems</a>
        <a href="{{ route('admin.settings.index') }}" class="text-sm font-medium text-slate-700 underline">Settings & limits</a>
        <a href="{{ route('admin.smtp.index') }}" class="text-sm font-medium text-slate-700 underline">SMTP</a>
    </div>
</x-layout>

<x-layout>
    <x-slot:title>Subsystems</x-slot:title>

    <x-page-header
        title="Subsystems"
        description="Stop a part of the platform without stopping the rest. Operator choice overrides the configured default and is persisted."
    />

    <x-alert variant="info" class="mb-6">
        These switches are stored in <code class="font-mono text-xs">system_settings</code> and survive
        <code class="font-mono text-xs">cache:clear</code>. That is deliberate in one direction and the
        opposite in the other: a kill switch that a cache clear silently resets is worse than no kill
        switch, but so is evidence of a fault that a cache clear erases.
    </x-alert>

    <div class="space-y-4">
        @foreach ($subsystems as $subsystem)
            <x-card>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="text-sm font-semibold text-slate-900">{{ $subsystem['enum']->label() }}</h2>
                        <p class="mt-1 text-sm text-slate-600">
                            <span class="font-mono text-xs">{{ $subsystem['enum']->value }}</span>
                            &middot; depends on the
                            <span class="font-mono text-xs">{{ $subsystem['enum']->subject()->value }}</span>
                            capability.
                        </p>
                        <p class="mt-2 text-xs text-slate-500">
                            Configured default:
                            <strong>{{ $subsystem['default'] ? 'enabled' : 'disabled' }}</strong>.
                            Current state:
                            <strong>{{ $subsystem['overridden'] ? 'overridden' : 'not overridden' }}</strong>.
                        </p>
                    </div>

                    <div class="flex items-center gap-3">
                        <x-status-badge :status="$subsystem['enabled'] ? 'active' : 'failed'"
                                       :label="$subsystem['enabled'] ? 'Enabled' : 'Disabled'" />

                        @if ($canManage)
                            <div class="flex flex-wrap gap-2">
                                @if ($subsystem['enabled'])
                                    <form method="POST" action="{{ route('admin.system.subsystems.disable', $subsystem['enum']) }}"
                                          onsubmit="return confirm('Disable {{ $subsystem['enum']->label() }}? Operations that need it will refuse to run.')">
                                        @csrf
                                        <x-button type="submit" variant="danger">Disable</x-button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('admin.system.subsystems.enable', $subsystem['enum']) }}">
                                        @csrf
                                        <x-button type="submit" variant="primary">Enable</x-button>
                                    </form>
                                @endif

                                @if ($subsystem['overridden'])
                                    <form method="POST" action="{{ route('admin.system.subsystems.reset', $subsystem['enum']) }}">
                                        @csrf
                                        <x-button type="submit" variant="secondary">Reset to default</x-button>
                                    </form>
                                @endif
                            </div>
                        @else
                            <p class="max-w-40 text-right text-xs text-slate-500">
                                Changing a switch needs <code class="font-mono">system.manage</code>.
                            </p>
                        @endif
                    </div>
                </div>
            </x-card>
        @endforeach
    </div>
</x-layout>

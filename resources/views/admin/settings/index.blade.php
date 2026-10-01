<x-layout>
    <x-slot:title>Settings</x-slot:title>

    <x-page-header
        title="Settings & limits"
        description="What this installation is configured to permit. Read-only by design."
    />

    <x-alert variant="warning" title="These values are not editable here" class="mb-6">
        They are read from the environment through <code class="font-mono text-xs">config/sender.php</code>.
        A web form that rewrote them would persist into the next deployment, would have to reconcile the
        <code class="font-mono text-xs">.env</code> with cached configuration, and would bypass the
        invariants checked at boot. Change them in the environment, then confirm with
        <code class="font-mono text-xs">php artisan sender:diagnose</code>.
    </x-alert>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-card title="Deployment limits"
               description="Ceilings this installation imposes. Distinct from host requirements, entitlements and usage.">
            <dl class="divide-y divide-slate-100 text-sm">
                @foreach ($limits as $limit)
                    <div class="flex items-center justify-between gap-3 py-2">
                        <dt class="text-slate-600">{{ $limit['label'] }}</dt>
                        <dd class="font-mono text-slate-800">{{ number_format($limit['value']) }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-card>

        <div class="space-y-6">
            <x-card title="Queue reservation">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-600">Driver</dt>
                        <dd class="font-mono text-slate-800">{{ $queue['driver'] }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-600">retry_after</dt>
                        <dd class="font-mono text-slate-800">{{ $queue['retry_after'] }}s</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-600">Reservation margin</dt>
                        <dd class="font-mono text-slate-800">{{ $queue['reservation_margin'] }}s</dd>
                    </div>
                </dl>
            </x-card>

            <x-card title="Host requirements"
                   description="What the platform asks of the host. Satisfied, not configured.">
                <dl class="divide-y divide-slate-100 text-sm">
                    @foreach ($requirements as $requirement)
                        <div class="flex items-center justify-between gap-3 py-2">
                            <dt class="text-slate-600">{{ $requirement['label'] }}</dt>
                            <dd class="font-mono text-slate-800">{{ $requirement['value'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-card>

            <x-card title="Capability subjects">
                <ul class="space-y-1 text-sm text-slate-700">
                    @foreach ($capabilities as $value => $label)
                        <li><span class="font-mono text-xs">{{ $value }}</span> &mdash; {{ $label }}</li>
                    @endforeach
                </ul>
            </x-card>
        </div>
    </div>
</x-layout>

<x-layout>
    <x-slot:title>Diagnostics</x-slot:title>

    <x-page-header
        title="Host diagnostics"
        description="Everything the platform needs from this machine, measured rather than assumed."
    >
        @if ($enabled)
            <x-slot:actions>
                <x-status-badge :status="$overall->value" :label="$overall->label()" />
            </x-slot:actions>
        @endif
    </x-page-header>

    @unless ($enabled)
        {{--
            The page renders either way. A navigation link that 404s by
            configuration teaches operators to distrust the navigation, and the
            detailed report is not secret — it is behind system.view. What
            matters is that the disabled state is stated rather than producing a
            broken page.
        --}}
        <x-alert variant="warning" title="Detailed diagnostics are disabled" class="mb-6">
            Set <code class="font-mono text-xs">SENDER_DIAGNOSTICS_ENABLED=true</code> to show the
            full report below. The
            <a href="{{ route('admin.system.index') }}" class="font-medium underline">system overview</a>
            and <code class="font-mono text-xs">php artisan sender:diagnose</code> remain available
            and show what is needed to act.
        </x-alert>

        <x-card title="What this page shows">
            <ul class="space-y-1.5 text-sm text-slate-700">
                <li>Host requirements and extensions</li>
                <li>Storage and queue reservation safety</li>
                <li>Capability status per subject</li>
                <li>Subsystem and entitlement availability</li>
            </ul>
        </x-card>
    @else
        <x-card title="Host checks">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="py-2 pr-4">Capability</th>
                        <th class="py-2 pr-4">Status</th>
                        <th class="py-2">Detail</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($report->checks as $check)
                        <tr class="align-top">
                            <td class="py-2 pr-4 font-mono text-xs text-slate-700">{{ $check->name }}</td>
                            <td class="py-2 pr-4">
                                <x-status-badge :status="$check->capability->value" />
                            </td>
                            <td class="py-2 text-slate-600">
                                {{ $check->detail }}

                                @if ($check->capability->value !== 'READY' && $check->remedies !== [])
                                    <ul class="mt-1 space-y-0.5">
                                        @foreach ($check->remedies as $remedy)
                                            <li class="text-xs text-slate-500">{{ $remedy }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-card>

        <div class="mt-8 grid gap-6 lg:grid-cols-2">
            <x-card title="Capabilities"
                   description="A capability is Unknown until something establishes its state.">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="py-2 pr-4">Subject</th>
                            <th class="py-2 pr-4">Status</th>
                            <th class="py-2">Required</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach (\App\Domain\System\Enums\CapabilitySubject::cases() as $subject)
                            @php($status = $capabilities->status($subject))
                            <tr>
                                <td class="py-2 pr-4 text-slate-700">{{ $subject->label() }}</td>
                                <td class="py-2 pr-4"><x-status-badge :status="$status->value" /></td>
                                <td class="py-2 text-slate-500">{{ $subject->isRequired() ? 'yes' : 'no' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>

            <x-card title="Subsystems & availability"
                   description="Operator control combined with capability and entitlement.">
                <ul class="space-y-3">
                    @foreach ($availability as $operation => $result)
                        <li class="border-b border-slate-100 pb-3 last:border-0 last:pb-0">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-sm font-medium text-slate-800">
                                    {{ Str::headline(str_replace('.', ' ', (string) $operation)) }}
                                </span>
                                <x-status-badge :status="$result->state->value" />
                            </div>
                            <p class="mt-1 text-sm text-slate-600">{{ $result->explanation }}</p>
                            <p class="mt-0.5 font-mono text-xs text-slate-400">{{ $result->reason?->value ?? '—' }}</p>
                        </li>
                    @endforeach
                </ul>
            </x-card>
        </div>
    @endunless
</x-layout>

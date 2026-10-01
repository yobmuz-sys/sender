<x-layout>
    <x-slot:title>Diagnostics</x-slot:title>

    <div class="rounded-lg border border-slate-200 bg-white p-8 shadow-sm">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Host diagnostics</h1>

            <span class="rounded-full px-3 py-1 text-xs font-semibold
                {{ match ($report->overall->value) {
                    'READY' => 'bg-emerald-100 text-emerald-800',
                    'DEGRADED' => 'bg-amber-100 text-amber-800',
                    'UNKNOWN' => 'bg-slate-200 text-slate-700',
                    default => 'bg-red-100 text-red-800',
                } }}">{{ $report->overall->label() }}</span>
        </div>

        <p class="mt-2 text-sm text-slate-600">
            Everything the platform needs from this machine, measured rather than assumed.
        </p>

        <table class="mt-6 w-full text-left text-sm">
            <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="py-2">Capability</th>
                    <th class="py-2">Status</th>
                    <th class="py-2">Detail</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($report->checks as $check)
                    <tr>
                        <td class="py-2 pr-4 font-mono text-xs text-slate-700">{{ $check->name }}</td>
                        <td class="py-2 pr-4">
                            <span @class([
                                'rounded-full px-2 py-0.5 text-xs font-semibold',
                                'bg-emerald-100 text-emerald-800' => $check->capability->value === 'READY',
                                'bg-amber-100 text-amber-800' => $check->capability->value === 'DEGRADED',
                                'bg-slate-200 text-slate-700' => $check->capability->value === 'UNKNOWN',
                                'bg-red-100 text-red-800' => $check->capability->value === 'UNAVAILABLE',
                            ])>{{ $check->capability->label() }}</span>
                        </td>
                        <td class="py-2 text-slate-600">{{ $check->detail }}</td>
                    </tr>

                    @if ($check->capability->value !== 'READY' && $check->remedies !== [])
                        <tr>
                            <td></td>
                            <td colspan="2" class="pb-3 text-xs text-slate-500">
                                @foreach ($check->remedies as $remedy)
                                    <span class="mr-3">{{ $remedy }}</span>
                                @endforeach
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>

        <h2 class="mt-10 font-semibold text-slate-900">Capabilities</h2>
        <p class="mt-1 text-sm text-slate-600">
            A capability is <span class="font-medium">Unknown</span> until something
            establishes its state. An unverified dependency is never reported as working.
        </p>

        <table class="mt-4 w-full text-left text-sm">
            <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="py-2">Capability</th>
                    <th class="py-2">Status</th>
                    <th class="py-2">Required</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach (\App\Domain\System\Enums\CapabilitySubject::cases() as $subject)
                    @php($status = $capabilities->status($subject))
                    <tr>
                        <td class="py-2 pr-4 text-slate-700">{{ $subject->label() }}</td>
                        <td class="py-2 pr-4">
                            <span @class([
                                'rounded-full px-2 py-0.5 text-xs font-semibold',
                                'bg-emerald-100 text-emerald-800' => $status->value === 'READY',
                                'bg-amber-100 text-amber-800' => $status->value === 'DEGRADED',
                                'bg-slate-200 text-slate-700' => $status->value === 'UNKNOWN',
                                'bg-red-100 text-red-800' => $status->value === 'UNAVAILABLE',
                            ])>{{ $status->label() }}</span>
                        </td>
                        <td class="py-2 text-slate-500">{{ $subject->isRequired() ? 'yes' : 'no' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <h2 class="mt-10 font-semibold text-slate-900">Subsystems</h2>
        <p class="mt-1 text-sm text-slate-600">
            Operator control, combined with capability and entitlement to decide whether an
            operation may proceed.
        </p>

        <table class="mt-4 w-full text-left text-sm">
            <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="py-2">State</th>
                    <th class="py-2">Reason</th>
                    <th class="py-2">Detail</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($availability as $result)
                    <tr>
                        <td class="py-2 pr-4">
                            <span @class([
                                'rounded-full px-2 py-0.5 text-xs font-semibold',
                                'bg-emerald-100 text-emerald-800' => $result->state->value === 'AVAILABLE',
                                'bg-amber-100 text-amber-800' => $result->state->value === 'UNVERIFIED',
                                'bg-red-100 text-red-800' => $result->state->value === 'BLOCKED',
                            ])>{{ $result->state->label() }}</span>
                        </td>
                        <td class="py-2 pr-4 font-mono text-xs text-slate-600">{{ $result->reason?->value ?? '—' }}</td>
                        <td class="py-2 text-slate-600">{{ $result->explanation }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-layout>
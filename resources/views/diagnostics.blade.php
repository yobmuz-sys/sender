<x-layout>
    <x-slot:title>Diagnostics</x-slot:title>

    <div class="rounded-lg border border-slate-200 bg-white p-8 shadow-sm">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Host diagnostics</h1>

            <span class="rounded-full px-3 py-1 text-xs font-semibold
                {{ match ($report->overall->value) {
                    'READY' => 'bg-emerald-100 text-emerald-800',
                    'DEGRADED' => 'bg-amber-100 text-amber-800',
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
    </div>
</x-layout>
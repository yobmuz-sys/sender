<x-layout>
    <x-slot:title>Scheduled runs</x-slot:title>

    <x-page-header
        title="Scheduled runs"
        description="Durable, append-only evidence of what the scheduler actually did."
    />

    {{-- Filter across every command that counts as scheduler evidence, so a
         deployment still calling the heartbeat is not shown as having no
         history at all. --}}
    <div class="mb-6 flex flex-wrap items-center gap-2">
        <a href="{{ route('admin.runs.index') }}"
           class="rounded-full px-3 py-1 text-xs font-semibold
                  {{ $command === '' ? 'bg-slate-800 text-white' : 'bg-slate-200 text-slate-700' }}">All</a>
        @foreach ($commands as $available)
            <a href="{{ route('admin.runs.index', ['command' => $available]) }}"
               class="rounded-full px-3 py-1 text-xs font-semibold
                      {{ $command === $available ? 'bg-slate-800 text-white' : 'bg-slate-200 text-slate-700' }}">
                {{ $available }}
            </a>
        @endforeach
    </div>

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-stat label="Cron capability"
                :value="$fresh ? 'READY' : ($latest === null ? 'UNKNOWN' : 'DEGRADED')"
                :hint="$latest === null ? 'no run recorded' : 'last run '.$latest->started_at->diffForHumans()" />
        <x-stat label="Last outcome" :value="$latest?->status->label() ?? '—'" />
        <x-stat label="Stale after" :value="$freshAfter.'s'" />
    </div>

    <x-alert variant="info" class="mb-6">
        Runs record an outcome, not merely that something ran. A command that is still being invoked but
        keeps failing is a different problem from one that has stopped being invoked, and the two have
        opposite remedies.
    </x-alert>

    @if ($runs->isEmpty())
        <x-empty-state
            title="No runs recorded"
            description="Add a cPanel cron entry calling sender:heartbeat, then evidence appears here after the first run."
        />
    @else
        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-5 py-3">Command</th>
                        <th class="px-5 py-3">Started</th>
                        <th class="px-5 py-3">Duration</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3">Detail</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($runs as $run)
                        <tr class="align-top hover:bg-slate-50">
                            <td class="px-5 py-3 font-mono text-xs text-slate-700">{{ $run->command }}</td>
                            <td class="px-5 py-3 whitespace-nowrap text-slate-600">
                                {{ $run->started_at->format('j M Y, H:i:s') }}
                            </td>
                            <td class="px-5 py-3 font-mono text-xs text-slate-600">
                                {{ $run->duration_ms === null ? '—' : $run->duration_ms.'ms' }}
                            </td>
                            <td class="px-5 py-3">
                                <x-status-badge :status="$run->status->value" :label="$run->status->label()" />
                            </td>
                            <td class="px-5 py-3 text-xs text-slate-600">
                                @if ($run->error)
                                    <span class="font-mono text-rose-700">{{ $run->error }}</span>
                                @elseif ($run->processed_count || $run->failed_count)
                                    <span class="font-mono">
                                        {{ $run->processed_count }} processed, {{ $run->failed_count }} failed
                                    </span>
                                @else
                                    <span class="text-slate-400">&mdash;</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-layout>

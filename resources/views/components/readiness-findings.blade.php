{{--
    One account's readiness findings.

    Shared by both deliverability pages. Each fact is rendered at the strength it
    was established, and `Not established` is styled distinctly from a pass so a
    gap cannot be mistaken for a result.
--}}
@props(['report'])

<div class="space-y-3">
    @forelse ($report->findings as $finding)
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 py-2 last:border-0">
            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-slate-900">{{ $finding->check }}</p>
                <p class="mt-0.5 text-sm text-slate-600">{{ $finding->detail }}</p>
            </div>
            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium {{ $finding->level->badgeClass() }}">
                {{ $finding->level->label() }}
            </span>
        </div>
    @empty
        <p class="text-sm text-slate-600">Nothing to report.</p>
    @endforelse
</div>
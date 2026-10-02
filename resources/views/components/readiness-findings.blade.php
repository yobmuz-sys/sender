{{--
    One account's readiness findings.

    Shared by both deliverability pages. Each fact is rendered at the strength it
    was established for the traffic being decided, and `Not established` is styled
    distinctly from a pass so a gap cannot be mistaken for a result.

    A finding scoped to bulk marketing is labelled as such. Without that, an
    absent DMARC record reads as a broken transport rather than as a gap in a
    sending policy, which sends the customer off to fix the wrong thing.
--}}
@props(['report', 'mode' => \App\Domain\Mail\TrafficMode::BulkMarketing])

<div class="space-y-3">
    @forelse ($report->findings as $finding)
        @php
            $level = $finding->levelFor($mode);
        @endphp
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 py-2 last:border-0">
            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-slate-900">
                    {{ $finding->check }}
                    @if ($finding->scope === \App\Domain\Mail\FindingScope::BulkOnly)
                        <span class="ml-1 rounded-full bg-slate-100 px-1.5 py-0.5 text-[0.65rem] font-normal text-slate-600">
                            bulk senders
                        </span>
                    @endif
                </p>
                <p class="mt-0.5 text-sm text-slate-600">{{ $finding->detail }}</p>
            </div>
            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium {{ $level->badgeClass() }}">
                {{ $level->label() }}
            </span>
        </div>
    @empty
        <p class="text-sm text-slate-600">Nothing to report.</p>
    @endforelse
</div>
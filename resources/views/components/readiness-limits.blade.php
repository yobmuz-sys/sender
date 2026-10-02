{{-- What a readiness report can and cannot establish. Rendered on both pages. --}}
@props(['limits'])

@if ($limits)
    <div class="rounded-lg border border-slate-200 bg-slate-50 p-5">
        <h2 class="text-sm font-semibold text-slate-900">What this report is</h2>
        <div class="mt-3 grid gap-4 sm:grid-cols-2">
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-emerald-700">It can establish</p>
                <ul class="mt-1 list-disc space-y-1 pl-5 text-sm text-slate-700">
                    @foreach ($limits['proves'] as $proves)
                        <li>{{ $proves }}</li>
                    @endforeach
                </ul>
            </div>
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-rose-700">It cannot establish</p>
                <ul class="mt-1 list-disc space-y-1 pl-5 text-sm text-slate-700">
                    @foreach ($limits['cannot_prove'] as $cannot)
                        <li>{{ $cannot }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
        <p class="mt-4 border-t border-slate-200 pt-3 text-sm text-slate-700">
            These checks improve the odds that mail is delivered legitimately. They do not guarantee inbox placement:
            that decision belongs to the receiving provider, using signals this platform cannot observe. There is no
            spam score here because no local calculation can produce an honest one.
        </p>
    </div>
@endif
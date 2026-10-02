@props(['progress' => null])

{{--
    The progress bar.

    Rendered only when a real denominator exists. A bar over "0 of 0" or over a
    list nobody has begun checking would be a percentage of nothing, and the
    whole point of TaskProgress is that it can say so — see hasDenominator().
--}}
@if ($progress?->hasDenominator())
    @php($percent = $progress->percent())
    <div class="mt-2" role="progressbar" aria-valuenow="{{ $percent }}"
         aria-valuemin="0" aria-valuemax="100"
         aria-label="{{ $progress->label()['title'] }}">
        {{-- Segment blocks rather than a proportional fill: at 82% the reader
             sees "how much is left" as clearly as "how much is done", which a
             smooth bar does not. --}}
        <div class="flex gap-0.5" aria-hidden="true">
            @for ($block = 0; $block < 20; $block++)
                @if ($block < (int) round($percent / 5))
                    <span class="h-2 flex-1 rounded-sm bg-sky-500"></span>
                @else
                    <span class="h-2 flex-1 rounded-sm bg-slate-200"></span>
                @endif
            @endfor
        </div>
        <p class="mt-1 text-xs text-slate-600">
            {{ $percent }}% &mdash; {{ $progress->label()['title'] }}
        </p>
    </div>
@else
    <p class="mt-2 text-xs text-slate-600">{{ $progress->label()['detail'] ?? '' }}</p>
@endif
@props(['progress' => null])

{{--
    The progress bar.

    Shared by anything with a real denominator, which is currently two unrelated
    things: an extraction being checked and a campaign being sent. It renders only
    when that thing can state a percentage, and says why it cannot when it cannot —
    see TaskProgress::hasDenominator() and CampaignProgress::hasDenominator().

    The contract is deliberately two methods and a label: hasDenominator(),
    percent() and label() returning {title, detail}. Anything more specific would
    have put one domain's rules in the middle of the other's screen.
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
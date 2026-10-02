@props(['item', 'url', 'icon' => null, 'pending' => false, 'nested' => false])

@php
    /*
     * One navigation entry, and the only place an entry is drawn.
     *
     * The active state is carried by three things at once — a background, a weight
     * and a left marker — plus `aria-current` for anything that reads the document
     * rather than looking at it, because colour alone is not an answer for a person
     * who cannot see it.
     *
     * A pending entry says so in words rather than pretending to be a live route,
     * which is why the label is a separate element from the link text.
     */
    $href = route($item->route);
    $active = $item->isActive($url, $href);
@endphp

<a href="{{ $href }}"
   @if ($active) aria-current="page" @endif
   @class([
       'group flex min-h-11 items-center gap-3 rounded-lg text-sm font-medium transition',
       'pl-3 pr-3' => ! $nested,
       'pl-9 pr-3 text-sm' => $nested,
       'bg-indigo-50 font-semibold text-indigo-900 ring-1 ring-inset ring-indigo-200' => $active,
       'text-slate-700 hover:bg-slate-100 hover:text-slate-900' => ! $active,
   ])>
    @if ($icon)
        <span @class([
            'shrink-0',
            'text-indigo-700' => $active,
            'text-slate-400 group-hover:text-slate-600' => ! $active,
        ]) aria-hidden="true">
            {!! $icon !!}
        </span>
    @endif

    <span class="min-w-0 flex-1 truncate">{{ $item->label }}</span>

    @if ($pending)
        <span class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-800 ring-1 ring-inset ring-amber-200">
            Coming soon
        </span>
    @endif
</a>
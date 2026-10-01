@props(['item', 'url'])
@php
    $href = route($item->route);
    $active = $item->isActive($url, $href);
@endphp
<a href="{{ $href }}"
   @class([
       'rounded-md px-3 py-2 text-sm font-medium transition',
       'bg-slate-900 text-white' => $active,
       'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! $active,
   ])
   @if ($active) aria-current="page" @endif>
    {{ $item->label }}
</a>
@end
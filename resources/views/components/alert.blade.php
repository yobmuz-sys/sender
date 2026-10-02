@props(['variant' => 'info', 'title' => null])
@php
    $styles = [
        'info' => 'border-sky-200 bg-sky-50 text-sky-900',
        'success' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
        'warning' => 'border-amber-200 bg-amber-50 text-amber-900',
        'danger' => 'border-rose-200 bg-rose-50 text-rose-900',
    ];
@endphp
<div role="status" {{ $attributes->class(['rounded-md border px-4 py-3 text-sm', $styles[$variant] ?? $styles['info']]) }}>
    @isset($title)
        <p class="font-semibold">{{ $title }}</p>
    @endisset

    <div class="{{ $title ? 'mt-1' : '' }}">{{ $slot }}</div>
</div>

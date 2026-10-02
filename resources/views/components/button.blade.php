@props(['variant' => 'primary', 'href' => null, 'type' => null])

@php
    $base = 'inline-flex items-center justify-center rounded-md px-3 py-2 text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-50';
    $variants = [
        'primary' => 'bg-slate-900 text-white hover:bg-slate-700',
        'secondary' => 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50',
        'danger' => 'bg-rose-600 text-white hover:bg-rose-500',
        'ghost' => 'text-slate-600 hover:bg-slate-100',
    ];
    $class = $base.' '.($variants[$variant] ?? $variants['primary']);
@endphp

@if ($href && $slot->isEmpty() === false && $type === null)
    <a href="{{ $href }}" {{ $attributes->class($class) }}>{{ $slot }}</a>
@else
    <button type="{{ $type ?? 'submit' }}" {{ $attributes->class($class) }}>{{ $slot }}</button>
@endif

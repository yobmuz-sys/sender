@props(['status', 'label' => null])

@php
    // Capability colours are defined once so a status never renders as a
    // different meaning on two different pages.
    $palette = [
        'READY' => 'bg-emerald-100 text-emerald-800 ring-emerald-200',
        'DEGRADED' => 'bg-amber-100 text-amber-800 ring-amber-200',
        'UNKNOWN' => 'bg-slate-100 text-slate-700 ring-slate-200',
        'UNAVAILABLE' => 'bg-rose-100 text-rose-800 ring-rose-200',

        // Plain English statuses used outside the capability vocabulary.
        'ok' => 'bg-emerald-100 text-emerald-800 ring-emerald-200',
        'active' => 'bg-emerald-100 text-emerald-800 ring-emerald-200',
        'suspended' => 'bg-rose-100 text-rose-800 ring-rose-200',
        'pending' => 'bg-slate-100 text-slate-700 ring-slate-200',
        'succeeded' => 'bg-emerald-100 text-emerald-800 ring-emerald-200',
        'running' => 'bg-sky-100 text-sky-800 ring-sky-200',
        'failed' => 'bg-rose-100 text-rose-800 ring-rose-200',
    ];
@endphp

<span {{ $attributes->class([
    'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
    $palette[$status] ?? 'bg-slate-100 text-slate-700 ring-slate-200',
]) }}>
    {{ $label ?? $status }}
</span>

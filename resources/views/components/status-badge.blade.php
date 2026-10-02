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

        // The task lifecycle. `amber` is the running pair — finding addresses
        // and checking them are both "in progress", and a badge that coloured
        // them differently would imply they were different kinds of wait.
        'queued' => 'bg-slate-100 text-slate-700 ring-slate-200',
        'extracting' => 'bg-amber-100 text-amber-800 ring-amber-200',
        'validating' => 'bg-amber-100 text-amber-800 ring-amber-200',
        'ready' => 'bg-emerald-100 text-emerald-800 ring-emerald-200',
        'cancelled' => 'bg-slate-100 text-slate-600 ring-slate-200',

        // The four validation classifications, keyed by the tone their enum
        // returns. These four are the most important colours in the product: a
        // green "likely active" and a red "confirmed inactive" are what a
        // customer reads the whole report to find.
        'emerald' => 'bg-emerald-100 text-emerald-800 ring-emerald-200',
        'rose' => 'bg-rose-100 text-rose-800 ring-rose-200',
        'amber' => 'bg-amber-100 text-amber-800 ring-amber-200',
        'slate' => 'bg-slate-100 text-slate-700 ring-slate-200',

        // An address the checker never reached. Distinct from `unknown`, which
        // means a check ran and could not conclude — a different fact, and one a
        // customer acting on a list needs to be able to tell apart.
        'unreached' => 'bg-slate-50 text-slate-500 ring-slate-200',
    ];
@endphp

<span {{ $attributes->class([
    'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
    $palette[$status] ?? 'bg-slate-100 text-slate-700 ring-slate-200',
]) }}>
    {{ $label ?? $status }}
</span>

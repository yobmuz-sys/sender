@props(['report'])

@php
    // The platform's own readiness rendering, reused rather than re-implemented:
    // a campaign's checks must look like the transport checks they partly are, so
    // the same colour cannot mean two things on two pages.
    $symbols = [
        'pass' => "\u{2713}",
        'warn' => '!',
        'block' => "\u{00D7}",
        'unknown' => '?',
    ];
@endphp

@if ($report->checks()->isEmpty())
    <p class="text-sm text-slate-600">
        Nothing has been selected yet, so there is nothing to check. Choose a template, a list and a
        sending transport to see whether this campaign could go out.
    </p>
@else
    <ul class="space-y-3">
        @foreach ($report->checks() as $check)
            <li class="flex gap-3">
                <span class="inline-flex h-fit shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium {{ $check->level->badgeClass() }}">
                    <span aria-hidden="true">{{ $symbols[$check->level->value] ?? '?' }}</span>
                    {{ $check->level->label() }}
                </span>

                <div class="min-w-0">
                    <p class="text-sm font-medium text-slate-900">{{ $check->label }}</p>
                    <p class="text-sm text-slate-600">{{ $check->detail }}</p>
                </div>
            </li>
        @endforeach
    </ul>
@endif
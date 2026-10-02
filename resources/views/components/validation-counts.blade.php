@props(['statuses', 'counts' => null])

{{--
    One task's four validation outcomes.

    The wording is the whole product for a person who does not know what SMTP
    is. Each row says what was *observed*, never what it implies: "the recipient
    server accepted this address" is checkable against the evidence, "this
    mailbox exists" is a claim the platform cannot make.

    Nothing here says "active" without "likely", and nothing says "inactive"
    without "confirmed". Those two qualifiers are the accuracy rule stated to
    the person who will act on it.
--}}
<div {{ $attributes->class('grid gap-3 sm:grid-cols-2') }}>
    @foreach ($statuses as $status)
        <div class="rounded-lg border border-slate-200 bg-white px-4 py-3">
            <div class="flex items-baseline justify-between gap-3">
                <span class="text-sm font-medium text-slate-800">{{ $status->label() }}</span>
                @if ($counts !== null)
                    <span class="font-mono text-lg font-semibold text-slate-900">
                        {{ number_format($counts[$status->value] ?? 0) }}
                    </span>
                @endif
            </div>
            <p class="mt-1 text-xs text-slate-600">{{ $status->explanation() }}</p>
        </div>
    @endforeach
</div>
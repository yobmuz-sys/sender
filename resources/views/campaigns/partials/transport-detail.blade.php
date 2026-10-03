@php
    /**
     * What the chosen transport will send through.
     *
     * Everything here is public information a customer already owns: the server it
     * connects to, the address messages will claim to come from, and the state
     * {@see SmtpAccount::effectiveStatus()} derives. No credential, no username, no
     * fingerprint of the stored password is rendered — the campaign builder is a page
     * a customer may leave on a shared screen, and a page that displayed part of a
     * secret would be a new way to leak one.
     *
     * The status shown is the effective one, not the stored column, so a transport
     * whose verification has gone stale reads as stale here rather than as verified
     * and then refusing at the start button.
     */
    $status = $account->effectiveStatus();
@endphp

<div class="mt-4 rounded-md bg-slate-50 px-4 py-3 ring-1 ring-inset ring-slate-200">
    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <p class="text-sm font-semibold text-slate-900">{{ $account->host }}</p>
        <p class="text-xs font-medium {{ $status->isUsable() ? 'text-emerald-700' : 'text-amber-800' }}">
            {{ $status->label() }}
        </p>
    </div>

    <p class="mt-2 text-sm text-slate-700">
        <span class="font-medium text-slate-600">From:</span>
        <span class="font-mono text-xs">{{ $account->from_name ? $account->from_name.' <'.$account->from_address.'>' : $account->from_address }}</span>
    </p>

    <p class="mt-1 text-xs text-slate-500">{{ $status->explanation() }}</p>
</div>
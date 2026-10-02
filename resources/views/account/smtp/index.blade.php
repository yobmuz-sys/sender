<x-layout>
    <x-slot:title>Mail transports</x-slot:title>

    <x-page-header
        title="Mail transports"
        description="The SMTP accounts your mail is sent through. Platform staff may supply one for you; a transport they manage is read-only here."
    />

    <x-flash />

    <x-card>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-slate-600">
                You may have several transports, but sending uses one chosen per campaign. Nothing is tried
                automatically or rotated for you.
            </p>
            <a href="{{ route('account.smtp.create') }}"
               class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                Add a transport
            </a>
        </div>
    </x-card>

    <div class="mt-6">
        @forelse ($accounts as $account)
            @php $status = $account->effectiveStatus(); @endphp
            <x-card class="mb-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <a href="{{ route('account.smtp.show', $account) }}"
                               class="text-base font-medium text-slate-900 hover:underline">{{ $account->label }}</a>
                            <x-status-badge :status="$status->isUsable() ? 'READY' : 'UNKNOWN'"
                                            :label="$status->label()" />
                            @unless ($account->ownerMayEdit())
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
                                    {{ $account->management_mode->label() }}
                                </span>
                            @endunless
                        </div>
                        <dl class="mt-3 grid gap-x-8 gap-y-1 text-sm sm:grid-cols-2">
                            <div class="flex gap-2">
                                <dt class="text-slate-500">Provider:</dt>
                                <dd class="text-slate-900">{{ $account->provider->label() }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-slate-500">Endpoint:</dt>
                                <dd class="text-slate-900">{{ $account->host }}:{{ $account->port }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-slate-500">Encryption:</dt>
                                <dd class="text-slate-900">{{ $account->encryption->value }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-slate-500">From:</dt>
                                <dd class="text-slate-900">{{ $account->from_address }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="flex shrink-0 flex-col gap-2">
                        <a href="{{ route('account.smtp.show', $account) }}"
                           class="text-sm font-medium text-slate-900 hover:underline">Details</a>
                        @if ($account->ownerMayEdit())
                            <a href="{{ route('account.smtp.edit', $account) }}"
                               class="text-sm text-slate-600 hover:text-slate-900">Edit</a>
                        @endif
                        <a href="{{ route('account.deliverability.index') }}"
                           class="text-sm text-slate-600 hover:text-slate-900">Sending health</a>
                    </div>
                </div>
            </x-card>
        @empty
            <x-empty-state
                title="No transports configured"
                description="Add an SMTP account to send through your own provider, or ask platform staff to assign one to you."
            />
        @endforelse
    </div>
</x-layout>
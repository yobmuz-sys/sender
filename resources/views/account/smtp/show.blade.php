<x-layout>
    <x-slot:title>{{ $account->label }}</x-slot:title>

    <x-page-header
        :title="$account->label"
        description="Transport details and verification. A successful test proves the server accepted a message, not that anyone received it."
    />

    <x-flash />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-card title="Transport">
                <dl class="grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
                    <div class="flex justify-between gap-4 sm:block">
                        <dt class="text-slate-500 sm:mb-0.5">Status</dt>
                        <dd class="text-slate-900">
                            <x-status-badge :status="$account->effectiveStatus()->isUsable() ? 'READY' : 'UNKNOWN'"
                                            :label="$account->effectiveStatus()->label()" />
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4 sm:block">
                        <dt class="text-slate-500 sm:mb-0.5">Provider</dt>
                        <dd class="text-slate-900">{{ $account->provider->label() }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 sm:block">
                        <dt class="text-slate-500 sm:mb-0.5">Endpoint</dt>
                        <dd class="text-slate-900">{{ $account->host }}:{{ $account->port }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 sm:block">
                        <dt class="text-slate-500 sm:mb-0.5">Encryption</dt>
                        <dd class="text-slate-900">{{ $account->encryption->label() }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 sm:block">
                        <dt class="text-slate-500 sm:mb-0.5">Authentication</dt>
                        <dd class="text-slate-900">{{ $account->auth_mode->label() }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 sm:block">
                        <dt class="text-slate-500 sm:mb-0.5">Username</dt>
                        <dd class="text-slate-900">{{ $account->username ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 sm:block">
                        <dt class="text-slate-500 sm:mb-0.5">Password</dt>
                        <dd class="text-slate-900">{{ $account->hasSecret() ? 'Stored, encrypted' : 'None stored' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 sm:block">
                        <dt class="text-slate-500 sm:mb-0.5">From</dt>
                        <dd class="text-slate-900">{{ $account->from_address }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 sm:block">
                        <dt class="text-slate-500 sm:mb-0.5">Reply-To</dt>
                        <dd class="text-slate-900">{{ $account->reply_to ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 sm:block">
                        <dt class="text-slate-500 sm:mb-0.5">Managed by</dt>
                        <dd class="text-slate-900">{{ $account->management_mode->label() }}</dd>
                    </div>
                </dl>
            </x-card>

            <x-card title="Sending readiness">
                <x-readiness-findings :report="$readiness" />
            </x-card>

            @if ($verification)
                <x-card title="Last verification result">
                    <p class="text-sm text-slate-900">{{ $verification['summary'] }}</p>
                    @if (! empty($verification['error']))
                        <p class="mt-2 text-sm text-rose-700">{{ $verification['error'] }}</p>
                    @endif

                    @if (! empty($verification['stages']))
                        <table class="mt-4 w-full text-left text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                                    <th class="py-2">Stage</th>
                                    <th class="py-2">Result</th>
                                    <th class="py-2">Detail</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($verification['stages'] as $stage)
                                    <tr class="border-b border-slate-100 last:border-0">
                                        <td class="py-2 font-medium text-slate-900">{{ $stage['name'] }}</td>
                                        <td class="py-2">
                                            <x-status-badge :status="$stage['passed'] ? 'ok' : 'failed'"
                                                            :label="$stage['passed'] ? 'Proved' : 'Not proved'" />
                                        </td>
                                        <td class="py-2 text-slate-600">{{ $stage['detail'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif

                    <p class="mt-4 text-sm text-slate-600">
                        Acceptance is the strongest statement available from inside this application. It does not
                        prove the message reached a recipient, or that it was placed in an inbox rather than spam.
                    </p>
                </x-card>
            @endif
        </div>

        <div class="space-y-6">
            @if ($account->ownerMayEdit())
                <x-card title="Verify">
                    <form method="POST" action="{{ route('account.smtp.verify', $account) }}">
                        @csrf
                        <button type="submit"
                                class="w-full rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                            Test connection
                        </button>
                    </form>
                    <p class="mt-2 text-xs text-slate-500">
                        Resolves the host, connects and negotiates TLS. The credentials are not exercised.
                    </p>
                </x-card>

                <x-card title="Send a test message">
                    <form method="POST" action="{{ route('account.smtp.send-test', $account) }}" class="space-y-3">
                        @csrf
                        <div>
                            <x-input-label for="recipient" value="Send to" />
                            <x-text-input id="recipient" name="recipient" type="email" class="mt-1 block w-full"
                                          :value="old('recipient', auth()->user()->email)" />
                            <x-input-error :messages="$errors->get('recipient')" class="mt-1" />
                        </div>
                        <button type="submit"
                                class="w-full rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                            Send test message
                        </button>
                    </form>
                    <p class="mt-2 text-xs text-slate-500">
                        Also proves the credentials and that the server accepts a message. Uses your provider's quota,
                        so it is rate limited.
                    </p>
                </x-card>
            @else
                <x-alert variant="info" title="Managed by platform staff">
                    Verification is performed by an administrator for this transport.
                </x-alert>
            @endif

            <x-card title="Manage">
                @if ($account->ownerMayEdit())
                    <a href="{{ route('account.smtp.edit', $account) }}"
                       class="text-sm font-medium text-slate-900 hover:underline">Edit settings</a>
                @endif
                <a href="{{ route('account.smtp.index') }}" class="mt-2 block text-sm text-slate-600 hover:text-slate-900">
                    All transports
                </a>
                <a href="{{ route('account.deliverability.index') }}" class="mt-2 block text-sm text-slate-600 hover:text-slate-900">
                    Sending health
                </a>

                @if ($account->ownerMayEdit())
                    <form method="POST" action="{{ route('account.smtp.destroy', $account) }}" class="mt-4"
                          onsubmit="return confirm('Delete this transport? Stored credentials are removed.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-sm text-rose-700 hover:text-rose-900">Delete transport</button>
                    </form>
                @endif
            </x-card>
        </div>
    </div>
</x-layout>
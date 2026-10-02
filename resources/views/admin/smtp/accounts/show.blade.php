<x-layout>
    <x-slot:title>{{ $account->label }}</x-slot:title>

    <x-page-header
        :title="$account->label"
        :description="'Sending transport for '.($account->user?->email ?? 'no account').'. No credential is displayed on this page.'"
    />

    <x-flash />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-card title="Transport">
                <dl class="grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
                    <div class="flex justify-between gap-4 sm:block">
                        <dt class="text-slate-500 sm:mb-0.5">Owner</dt>
                        <dd class="text-slate-900">{{ $account->user?->email ?? '—' }}</dd>
                    </div>
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
                        <dt class="text-slate-500 sm:mb-0.5">Credential</dt>
                        <dd class="text-slate-900">{{ $account->hasSecret() ? 'Stored, encrypted' : 'None stored' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 sm:block">
                        <dt class="text-slate-500 sm:mb-0.5">From identity</dt>
                        <dd class="text-slate-900">{{ $account->from_address }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 sm:block">
                        <dt class="text-slate-500 sm:mb-0.5">Management</dt>
                        <dd class="text-slate-900">{{ $account->management_mode->label() }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 sm:block">
                        <dt class="text-slate-500 sm:mb-0.5">Verified</dt>
                        <dd class="text-slate-900">{{ $account->verified_at?->diffForHumans() ?? 'Never' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 sm:block">
                        <dt class="text-slate-500 sm:mb-0.5">Last failure</dt>
                        <dd class="text-slate-900">
                            @if ($account->last_failure_category)
                                {{ \App\Domain\Mail\SmtpFailureReason::tryFrom($account->last_failure_category)?->label() ?? $account->last_failure_category }}
                                <span class="text-slate-500">({{ $account->last_failure_at?->diffForHumans() }})</span>
                            @else
                                None recorded
                            @endif
                        </dd>
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
                        Acceptance is not delivery. Nothing in this report observes a recipient's mailbox.
                    </p>
                </x-card>
            @endif
        </div>

        <div class="space-y-6">
            @if ($canManage)
                <x-card title="Verify">
                    <form method="POST" action="{{ route('admin.smtp.accounts.verify', $account) }}">
                        @csrf
                        <button type="submit"
                                class="w-full rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                            Test connection
                        </button>
                    </form>

                    <form method="POST" action="{{ route('admin.smtp.accounts.send-test', $account) }}" class="mt-4 space-y-3">
                        @csrf
                        <div>
                            <x-input-label for="recipient" value="Send test to" />
                            <x-text-input id="recipient" name="recipient" type="email" class="mt-1 block w-full"
                                          :value="old('recipient', $account->from_address)" />
                            <x-input-error :messages="$errors->get('recipient')" class="mt-1" />
                        </div>
                        <button type="submit"
                                class="w-full rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                            Send test message
                        </button>
                    </form>
                </x-card>

                <x-card title="Switch off or on">
                    @if ($account->status->isSwitchedOff())
                        <form method="POST" action="{{ route('admin.smtp.accounts.status', [$account, 'unverified']) }}">
                            @csrf
                            <button type="submit"
                                    class="w-full rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                                Switch back on
                            </button>
                        </form>
                        <p class="mt-2 text-xs text-slate-500">
                            Switching on does not restore a verification. The account must be verified before it sends.
                        </p>
                    @else
                        <form method="POST" action="{{ route('admin.smtp.accounts.status', [$account, 'disabled']) }}">
                            @csrf
                            <button type="submit"
                                    class="w-full rounded-md border border-rose-300 px-4 py-2 text-sm font-medium text-rose-700 hover:bg-rose-50">
                                Switch this transport off
                            </button>
                        </form>
                        <p class="mt-2 text-xs text-slate-500">
                            A stopped transport is never used for sending, regardless of what else passes.
                        </p>
                    @endif
                </x-card>
            @endif

            @if ($canAssign)
                <x-card title="Reassign">
                    <form method="POST" action="{{ route('admin.smtp.accounts.assign', $account) }}" class="space-y-3">
                        @csrf
                        <div>
                            <x-input-label for="user_id" value="Assign to user id" />
                            <x-text-input id="user_id" name="user_id" type="number" class="mt-1 block w-full"
                                          :value="old('user_id', $account->user_id)" required />
                            <x-input-error :messages="$errors->get('user_id')" class="mt-1" />
                        </div>
                        <button type="submit"
                                class="w-full rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                            Reassign
                        </button>
                    </form>
                    <p class="mt-2 text-xs text-slate-500">
                        The row moves; the credential is not copied. The new owner sees the settings and never the
                        password.
                    </p>
                </x-card>
            @endif

            <x-card title="Manage">
                <a href="{{ route('admin.smtp.accounts.index') }}" class="block text-sm font-medium text-slate-900 hover:underline">
                    All SMTP accounts
                </a>
                @if ($canManage)
                    <a href="{{ route('admin.smtp.accounts.edit', $account) }}" class="mt-2 block text-sm text-slate-600 hover:text-slate-900">
                        Edit settings
                    </a>
                @endif
                <a href="{{ route('admin.users.smtp', $account->user_id) }}" class="mt-2 block text-sm text-slate-600 hover:text-slate-900">
                    Owner's transports
                </a>
                <a href="{{ route('admin.deliverability.index') }}" class="mt-2 block text-sm text-slate-600 hover:text-slate-900">
                    Sending health
                </a>

                @if ($canManage)
                    <form method="POST" action="{{ route('admin.smtp.accounts.destroy', $account) }}" class="mt-4"
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
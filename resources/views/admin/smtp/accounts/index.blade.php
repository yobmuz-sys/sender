<x-layout>
    <x-slot:title>SMTP accounts</x-slot:title>

    <x-page-header
        title="SMTP accounts"
        description="Every tenant's sending transport. Credentials are never displayed here, by this role or any other."
    />

    <x-flash />

    <x-card class="mb-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-slate-600">
                This page manages tenants' sending transports. The platform's own mail — password resets and address
                confirmation — is configured separately in the environment and reported on
                <a href="{{ route('admin.smtp.index') }}" class="underline">the SMTP page</a>.
            </p>
            @if ($canManage)
                <a href="{{ route('admin.smtp.accounts.create') }}"
                   class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                    Create a transport
                </a>
            @endif
        </div>
    </x-card>

    <x-card>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                        <th class="py-2 pr-4">User</th>
                        <th class="py-2 pr-4">Provider</th>
                        <th class="py-2 pr-4">Endpoint</th>
                        <th class="py-2 pr-4">From</th>
                        <th class="py-2 pr-4">Managed by</th>
                        <th class="py-2 pr-4">Verified</th>
                        <th class="py-2">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($accounts as $account)
                        @php $status = $account->effectiveStatus(); @endphp
                        <tr class="border-b border-slate-100 last:border-0">
                            <td class="py-2 pr-4">
                                <a href="{{ route('admin.smtp.accounts.show', $account) }}"
                                   class="font-medium text-slate-900 hover:underline">
                                    {{ $account->user?->email ?? '—' }}
                                </a>
                            </td>
                            <td class="py-2 pr-4 text-slate-700">{{ $account->provider->label() }}</td>
                            <td class="py-2 pr-4 text-slate-700">{{ $account->host }}:{{ $account->port }}</td>
                            <td class="py-2 pr-4 text-slate-700">{{ $account->from_address }}</td>
                            <td class="py-2 pr-4 text-slate-700">{{ $account->management_mode->label() }}</td>
                            <td class="py-2 pr-4 text-slate-700">
                                {{ $account->verified_at?->diffForHumans() ?? 'Never' }}
                            </td>
                            <td class="py-2">
                                <x-status-badge :status="$status->isUsable() ? 'READY' : 'UNKNOWN'"
                                                :label="$status->label()" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-6 text-center text-slate-500">
                                No tenant SMTP accounts exist yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($accounts->hasPages())
            <div class="mt-4">{{ $accounts->links() }}</div>
        @endif
    </x-card>

    @if ($canAssign)
        <p class="mt-4 text-sm text-slate-600">
            Reassigning a transport moves it rather than copying it, so a credential is never duplicated into a second
            row.
        </p>
    @endif
</x-layout>
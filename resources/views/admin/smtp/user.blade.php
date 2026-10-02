<x-layout>
    <x-slot:title>{{ $accountOwner->name }} — transports</x-slot:title>

    <x-page-header
        :title="'Transports for '.$accountOwner->email"
        description="Every sending transport assigned to this account."
    />

    <x-flash />

    <x-card class="mb-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-slate-600">
                @if ($canAssign)
                    Reassigning a transport from an account's page moves it rather than copying it, so one credential
                    never exists in two rows.
                @else
                    You can read transport metadata. Reassigning requires the assignment permission.
                @endif
            </p>
            @if ($canManage)
                <a href="{{ route('admin.smtp.accounts.create', ['user' => $accountOwner->getKey()]) }}"
                   class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                    Create for this account
                </a>
            @endif
        </div>
    </x-card>

    <x-card>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                        <th class="py-2 pr-4">Label</th>
                        <th class="py-2 pr-4">Provider</th>
                        <th class="py-2 pr-4">Endpoint</th>
                        <th class="py-2 pr-4">From</th>
                        <th class="py-2 pr-4">Managed by</th>
                        <th class="py-2">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($accounts as $account)
                        @php $status = $account->effectiveStatus(); @endphp
                        <tr class="border-b border-slate-100 last:border-0">
                            <td class="py-2 pr-4">
                                <a href="{{ route('admin.smtp.accounts.show', $account) }}"
                                   class="font-medium text-slate-900 hover:underline">{{ $account->label }}</a>
                            </td>
                            <td class="py-2 pr-4 text-slate-700">{{ $account->provider->label() }}</td>
                            <td class="py-2 pr-4 text-slate-700">{{ $account->host }}:{{ $account->port }}</td>
                            <td class="py-2 pr-4 text-slate-700">{{ $account->from_address }}</td>
                            <td class="py-2 pr-4 text-slate-700">{{ $account->management_mode->label() }}</td>
                            <td class="py-2">
                                <x-status-badge :status="$status->isUsable() ? 'READY' : 'UNKNOWN'"
                                                :label="$status->label()" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-6 text-center text-slate-500">
                                This account has no SMTP transports.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <p class="mt-4 text-sm">
        <a href="{{ route('admin.users.show', $accountOwner) }}" class="text-slate-600 hover:text-slate-900">
            Back to this account
        </a>
    </p>
</x-layout>
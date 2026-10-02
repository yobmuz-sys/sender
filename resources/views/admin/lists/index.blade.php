<x-layout>
    <x-slot:title>Lists</x-slot:title>

    <x-page-header
        title="Lists"
        description="Every named group of contacts across the platform, and the account each belongs to."
    />

    <x-alert variant="info" title="Read-only here" class="mb-6">
        A list belongs to the account that created it and is edited from that account. This page
        answers &ldquo;whose list is this and how big is it&rdquo;, which is the question a support
        conversation needs, and deliberately offers nothing that would change a customer's own
        audience.
    </x-alert>

    <x-card>
        @if ($lists->isEmpty())
            <x-empty-state title="No lists yet" description="No account has created a list." />
        @else
            <p class="mb-4 text-sm text-slate-600">
                {{ number_format($total) }} lists across {{ number_format($owners) }} accounts.
            </p>

            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="py-2 pr-4">Account</th>
                        <th class="py-2 pr-4">List</th>
                        <th class="py-2">Contacts</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($lists as $list)
                        <tr>
                            <td class="py-2 pr-4 text-slate-700">{{ $list->owner_email }}</td>
                            <td class="py-2 pr-4 font-medium text-slate-800">{{ $list->name }}</td>
                            <td class="py-2 font-mono text-slate-700">
                                {{ number_format($list->memberships_count) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="mt-4">{{ $lists->links() }}</div>
        @endif
    </x-card>
</x-layout>
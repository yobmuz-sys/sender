<x-layout>
    <x-slot:title>Users</x-slot:title>

    <x-page-header title="Users" description="Every account on this installation.">
        <x-slot:actions>
            @can('users.create')
                <a href="{{ route('admin.users.create') }}"
                   class="inline-flex items-center rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">
                    Add user
                </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <x-card class="mb-6">
        <form method="GET" action="{{ route('admin.users.index') }}" class="flex flex-wrap items-end gap-3">
            <div class="min-w-56 flex-1">
                <x-input-label for="search" value="Search" />
                <x-text-input id="search" name="search" type="search" class="mt-1 block w-full"
                              :value="$search" placeholder="Name or email" />
            </div>

            <div>
                <x-input-label for="role" value="Role" />
                <select id="role" name="role"
                        class="mt-1 block rounded-md border-slate-300 text-sm shadow-sm">
                    <option value="">All roles</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role->value }}" @selected($roleFilter === $role->value)>
                            {{ $role->label() }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="status" value="Status" />
                <select id="status" name="status"
                        class="mt-1 block rounded-md border-slate-300 text-sm shadow-sm">
                    <option value="">Any status</option>
                    <option value="active" @selected($statusFilter === 'active')>Active</option>
                    <option value="suspended" @selected($statusFilter === 'suspended')>Suspended</option>
                </select>
            </div>

            <x-button variant="secondary">Filter</x-button>

            @if ($search !== '' || $roleFilter !== '' || $statusFilter !== '')
                <a href="{{ route('admin.users.index') }}" class="pb-2 text-sm text-slate-600 hover:text-slate-900">Clear</a>
            @endif
        </form>
    </x-card>

    @if ($users->isEmpty())
        <x-empty-state
            title="No accounts match"
            description="Adjust the filters above, or create an account."
        />
    @else
        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-5 py-3">Name</th>
                        <th class="px-5 py-3">Role</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3">Email</th>
                        <th class="px-5 py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($users as $account)
                        <tr class="hover:bg-slate-50">
                            <td class="px-5 py-3 font-medium text-slate-900">
                                <a href="{{ route('admin.users.show', $account) }}" class="hover:underline">
                                    {{ $account->name }}
                                </a>
                            </td>
                            <td class="px-5 py-3 text-slate-600">{{ $account->role->label() }}</td>
                            <td class="px-5 py-3">
                                <x-status-badge :status="$account->status->value" :label="$account->status->label()" />
                            </td>
                            <td class="px-5 py-3 text-slate-600">{{ $account->email }}</td>
                            <td class="px-5 py-3 text-right">
                                @can('users.edit')
                                    <a href="{{ route('admin.users.edit', $account) }}"
                                       class="text-sm font-medium text-slate-700 hover:text-slate-900">Edit</a>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $users->links() }}</div>
    @endif
</x-layout>

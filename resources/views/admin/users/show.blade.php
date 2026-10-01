<x-layout>
    <x-slot:title>{{ $user->name }}</x-slot:title>

    <x-page-header :title="$user->name" :description="$user->email">
        <x-slot:actions>
            @can('users.edit')
                <a href="{{ route('admin.users.edit', $user) }}"
                   class="inline-flex items-center rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">
                    Edit
                </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-card title="Account">
                <dl class="grid gap-4 sm:grid-cols-2 text-sm">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500">Role</dt>
                        <dd class="mt-0.5 text-slate-800">{{ $user->role->label() }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500">Status</dt>
                        <dd class="mt-1"><x-status-badge :status="$user->status->value" :label="$user->status->label()" /></dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500">Email confirmation</dt>
                        <dd class="mt-1">
                            <x-status-badge :status="$user->hasVerifiedEmail() ? 'active' : 'pending'"
                                           :label="$user->hasVerifiedEmail() ? 'Confirmed' : 'Not confirmed'" />
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500">Created</dt>
                        <dd class="mt-0.5 text-slate-800">{{ $user->created_at?->format('j M Y, H:i') }}</dd>
                    </div>
                </dl>
            </x-card>

            @can('users.suspend')
                @if ($user->is(auth()->user()))
                    <x-alert variant="info">
                        This is your own account. You cannot change your own access here.
                    </x-alert>
                @elseif ($user->isSuspended())
                    <x-card title="Reinstate account">
                        <p class="text-sm text-slate-600">
                            {{ $user->name }} cannot sign in while suspended. Reinstating restores access
                            immediately and ends any session restriction applied at suspension time.
                        </p>
                        <form method="POST" action="{{ route('admin.users.reinstate', $user) }}" class="mt-4">
                            @csrf
                            @method('DELETE')
                            <x-button type="submit" variant="primary">Reinstate {{ $user->name }}</x-button>
                        </form>
                    </x-card>
                @else
                    <x-card title="Suspend account">
                        <p class="text-sm text-slate-600">
                            Suspending signs {{ $user->name }} out and blocks further sign-ins. Their role
                            is unchanged, so reinstating restores the account exactly as it was.
                        </p>
                        <form method="POST" action="{{ route('admin.users.suspend', $user) }}" class="mt-4"
                              onsubmit="return confirm('Suspend {{ $user->name }}? They will be signed out immediately.')">
                            @csrf
                            <x-button type="submit" variant="danger">Suspend {{ $user->name }}</x-button>
                        </form>
                    </x-card>
                @endif
            @endcan
        </div>

        <div>
            <x-card title="Permissions">
                @if ($user->role->permissions() === [])
                    <x-empty-state
                        title="No administrative permissions"
                        description="This is a customer account. Product entitlements are resolved separately."
                    />
                @else
                    <ul class="space-y-1 text-sm text-slate-700">
                        @foreach (\App\Domain\Users\Permission::groups() as $area => $permissions)
                            @foreach ($permissions as $permission)
                                <li class="flex items-center gap-2">
                                    <span class="text-slate-400">{{ $area }}</span>
                                    <x-status-badge status="{{ $user->role->allows($permission) ? 'active' : 'pending' }}"
                                                   label="{{ $permission }}" />
                                </li>
                            @endforeach
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>
    </div>
</x-layout>
<x-layout>
    <x-slot:title>Roles & permissions</x-slot:title>

    <x-page-header
        title="Roles & permissions"
        description="What each platform role grants. This matrix is generated from the role definitions, so it cannot drift from what the application actually enforces."
    />

    <x-alert variant="info" class="mb-6">
        Read-only at this stage. Permissions are defined in the role catalogue, which is also what
        registers the gates the application checks. Storing them separately would create a second
        answer to "what can this role do", and the two would disagree.
    </x-alert>

    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-5 py-3 text-left text-xs uppercase tracking-wide text-slate-500">Permission</th>
                    @foreach ($roles as $role)
                        <th class="px-5 py-3 text-center text-xs uppercase tracking-wide text-slate-500">
                            {{ $role->label() }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($groups as $area => $permissions)
                    <tr class="bg-slate-50/60">
                        <td colspan="{{ count($roles) + 1 }}" class="px-5 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            {{ $area }}
                        </td>
                    </tr>

                    @foreach ($permissions as $permission)
                        <tr class="hover:bg-slate-50">
                            <td class="px-5 py-3 font-mono text-xs text-slate-700">{{ $permission }}</td>
                            @foreach ($roles as $role)
                                <td class="px-5 py-3 text-center">
                                    @if ($role->allows($permission))
                                        <span class="text-emerald-600" title="Granted">
                                            <span class="sr-only">Granted</span>&check;
                                        </span>
                                    @else
                                        <span class="text-slate-300" title="Not granted">
                                            <span class="sr-only">Not granted</span>&mdash;
                                        </span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </div>

    <p class="mt-4 text-sm text-slate-600">
        {{ count(\App\Domain\Users\Permission::all()) }} permissions are registered as gates, so a typo in
        a route or a template fails during testing rather than silently denying access in production.
    </p>
</x-layout>

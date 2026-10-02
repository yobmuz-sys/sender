<x-layout>
    <x-slot:title>Lists</x-slot:title>

    <x-page-header
        title="Lists"
        description="Group your contacts so you can see and work with them separately. A contact belongs to as many lists as you like and is never copied between them."
    >
        <x-slot:actions>
            <x-button :href="route('lists.create')">New list</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($lists->isEmpty())
        <x-empty-state
            title="No lists yet"
            description="A list is a named group of contacts. Create one and paste addresses into it, or add addresses from a task's report."
        >
            <x-slot:actions>
                <x-button :href="route('lists.create')">New list</x-button>
            </x-slot:actions>
        </x-empty-state>
    @else
        <x-card>
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="py-2 pr-4">List</th>
                        <th class="py-2 pr-4">Contacts</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($lists as $list)
                        <tr>
                            <td class="py-2 pr-4">
                                <a href="{{ route('lists.show', $list) }}"
                                   class="font-medium text-slate-800 hover:underline">
                                    {{ $list->name }}
                                </a>
                                @if ($list->description)
                                    <span class="block text-xs text-slate-500">{{ $list->description }}</span>
                                @endif
                            </td>
                            <td class="py-2 pr-4 font-mono text-slate-700">
                                {{ number_format($list->memberships_count) }}
                            </td>
                            <td class="py-2 text-right">
                                <x-button variant="secondary" :href="route('lists.show', $list)">Open</x-button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="mt-4">{{ $lists->links() }}</div>
        </x-card>
    @endif
</x-layout>
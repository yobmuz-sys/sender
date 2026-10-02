<x-layout>
    <x-slot:title>{{ $list->name ?? 'New list' }}</x-slot:title>

    <x-page-header
        :title="$list->name ?? 'New list'"
        :description="'A named group of contacts. Adding addresses here does not contact anybody.'"
    >
        <x-slot:actions>
            @if ($list)
                <x-button variant="secondary" :href="route('lists.edit', $list)">Rename</x-button>
                <x-button :href="route('lists.index')">All lists</x-button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <x-card>
        <form method="POST"
              action="{{ $list ? route('lists.update', $list) : route('lists.store') }}">
            @csrf
            @if ($list)
                @method('PUT')
            @endif

            <div class="max-w-xl space-y-4">
                <div>
                    <x-input-label for="name" value="Name" />
                    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                                  :value="old('name', $list?->name)" required autofocus />
                    <x-input-error class="mt-1" :messages="$errors->get('name')" />
                </div>

                <div>
                    <x-input-label for="description" value="Description" />
                    <textarea id="description" name="description" rows="3"
                              class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500"
                              placeholder="What this list is for.">{{ old('description', $list?->description) }}</textarea>
                    <x-input-error class="mt-1" :messages="$errors->get('description')" />
                    <p class="mt-1 text-xs text-slate-500">Optional.</p>
                </div>

                <div class="flex items-center gap-3">
                    <x-primary-button>{{ $list ? 'Save changes' : 'Create list' }}</x-primary-button>
                    <a href="{{ $list ? route('lists.show', $list) : route('lists.index') }}"
                       class="text-sm text-slate-600 hover:underline">Cancel</a>
                </div>
            </div>
        </form>
    </x-card>

    @if ($list)
        <x-card title="Delete this list" class="mt-6">
            <p class="text-sm text-slate-600">
                The list and its membership records are removed. The contacts themselves are
                kept, along with their consent history and — importantly — any record that they
                asked not to be contacted.
            </p>
            <form method="POST" action="{{ route('lists.destroy', $list) }}" class="mt-4">
                @csrf
                @method('DELETE')
                <x-button variant="secondary" type="submit">Delete list</x-button>
            </form>
        </x-card>
    @endif
</x-layout>
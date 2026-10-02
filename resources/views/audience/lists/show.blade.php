@php
    // Computed once rather than per row: each of these is a count, and a list of
    // nine thousand members is entirely plausible for this product.
    $eligibleCount = $eligibility->countFor($list->user_id, $list);
    $memberCount = $members->total();
@endphp

<x-layout>
    <x-slot:title>{{ $list->name }}</x-slot:title>

    <x-page-header
        :title="$list->name"
        :description="$list->description ?: 'A named group of contacts.'"
    >
        <x-slot:actions>
            <x-button variant="secondary" :href="route('lists.edit', $list)">Rename</x-button>
            <x-button :href="route('lists.index')">All lists</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- Two numbers, deliberately, because they diverge the moment anything is
         excluded. Showing only the contact count would present a list of nine
         thousand as though it were ready to use. --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-2">
        <x-stat label="Contacts on this list" :value="number_format($memberCount)" />
        <x-stat label="Usable"
                :value="number_format($eligibleCount)"
                hint="Checked as reachable, with consent recorded and not suppressed." />
    </div>

    <x-alert variant="info" title="Sending is not available yet" class="mb-6">
        This stage builds the audience: the contacts, what checking found about them,
        who agreed to hear from you and who must never be contacted. Composing and
        sending a campaign is the next stage, and this list will be what it selects from.
    </x-alert>

    <x-card title="Add addresses" class="mb-6">
        <p class="mb-3 text-sm text-slate-600">
            Paste addresses separated by spaces, commas or one per line. Duplicates and
            addresses already in the list are ignored. They will appear as
            &ldquo;not checked&rdquo; until a task checks them.
        </p>
        <form method="POST" action="{{ route('lists.contacts.store', $list) }}">
            @csrf
            <textarea name="addresses" rows="5" required
                      class="block w-full rounded-md border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500"
                      placeholder="jane@example.com&#10;alex@example.com">{{ old('addresses') }}</textarea>
            <x-input-error class="mt-1" :messages="$errors->get('addresses')" />
            <div class="mt-3">
                <x-primary-button>Add addresses</x-primary-button>
            </div>
        </form>
    </x-card>

    <x-card title="Contacts">
        @if ($members->isEmpty())
            <x-empty-state
                title="This list is empty"
                description="Paste some addresses above, or add them from a task's report."
            />
        @else
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="py-2 pr-4">Email</th>
                        <th class="py-2 pr-4">Check</th>
                        <th class="py-2 pr-4">Permission</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($members as $membership)
                        @php
                            $contact = $membership->contact;
                            $consent = $consents->statusOf($contact);
                            $suppressed = $suppressions->isSuppressed($contact);
                        @endphp
                        <tr>
                            <td class="py-2 pr-4 font-mono text-xs text-slate-800">{{ $contact->email }}</td>
                            <td class="py-2 pr-4">
                                {{-- Suppression outranks everything on this row.
                                     An address somebody unsubscribed from does not
                                     become "likely active" because it also has a
                                     recorded consent. --}}
                                @if ($suppressed)
                                    <x-status-badge status="rose" label="Never contact" />
                                @elseif ($contact->validation_status)
                                    <x-status-badge :status="$contact->validation_status->tone()"
                                                    :label="$contact->validation_status->label()" />
                                @else
                                    <x-status-badge status="unreached" label="Not checked" />
                                @endif
                            </td>
                            <td class="py-2 pr-4">
                                <x-status-badge :status="$consent->tone()" :label="$consent->label()" />
                            </td>
                            <td class="py-2 text-right">
                                <form method="POST"
                                      action="{{ route('lists.contacts.destroy', [$list, $contact->id]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <x-button variant="secondary" type="submit">Remove</x-button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="mt-4">{{ $members->links() }}</div>
        @endif
    </x-card>
</x-layout>
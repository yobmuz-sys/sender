<x-layout>
    <x-slot:title>Extraction</x-slot:title>

    <x-page-header
        title="Extraction"
        description="Pasted text is processed in the background and the addresses found are kept with your account."
    >
        <x-slot:actions>
            <x-button :href="route('extractor.create')">New extraction</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($extractions->isEmpty())
        <x-empty-state
            title="No extractions yet"
            description="Paste text containing email addresses and the addresses will be extracted and stored against your account."
        >
            <x-slot:actions>
                <x-button :href="route('extractor.create')">New extraction</x-button>
            </x-slot:actions>
        </x-empty-state>
    @else
        <x-card>
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="py-2 pr-4">Extraction</th>
                        <th class="py-2 pr-4">Status</th>
                        <th class="py-2 pr-4">Found</th>
                        <th class="py-2 pr-4">Submitted</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($extractions as $extraction)
                        <tr>
                            <td class="py-2 pr-4">
                                <a href="{{ route('extractor.show', $extraction) }}"
                                   class="font-medium text-slate-800 hover:underline">
                                    #{{ $extraction->id }}
                                </a>
                                <span class="block text-xs text-slate-500">
                                    {{ $extraction->source_type === 'paste' ? 'Pasted text' : $extraction->source_type }}
                                </span>
                            </td>
                            <td class="py-2 pr-4">
                                <x-status-badge :status="$extraction->status->value"
                                                :label="$extraction->status->label()" />
                            </td>
                            <td class="py-2 pr-4 font-mono text-slate-700">{{ number_format($extraction->found_count) }}</td>
                            <td class="py-2 pr-4 text-slate-500">{{ $extraction->created_at?->diffForHumans() }}</td>
                            <td class="py-2 text-right">
                                <x-button variant="secondary" :href="route('extractor.show', $extraction)">
                                    View
                                </x-button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="mt-4">{{ $extractions->links() }}</div>
        </x-card>
    @endif
</x-layout>

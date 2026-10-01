<x-layout>
    <x-slot:title>Extraction #{{ $extraction->id }}</x-slot:title>

    <x-page-header
        title="Extraction #{{ $extraction->id }}"
        :description="'Submitted '.$extraction->created_at?->diffForHumans().'.'"
    >
        <x-slot:actions>
            @if ($extraction->found_count > 0)
                <x-button variant="secondary" :href="route('extractor.download', $extraction)">
                    Download CSV
                </x-button>
            @endif
        </x-slot:actions>
    </x-page-header>

    {{-- Each state says what it is and what to do next. A row stuck at
         "waiting" with no explanation is the failure this replaces. --}}
    @if ($extraction->status === \App\Domain\Extraction\ExtractionStatus::Pending)
        <x-alert variant="info" title="Waiting to be processed" class="mb-6">
            This extraction is queued. It is processed by the background worker on a schedule;
            there is nothing you need to do.
        </x-alert>
    @elseif ($extraction->status === \App\Domain\Extraction\ExtractionStatus::Processing)
        <x-alert variant="warning" title="Processing" class="mb-6">
            The worker picked this up {{ $extraction->started_at?->diffForHumans() }} and is
            still working on it.
        </x-alert>
    @elseif ($extraction->status === \App\Domain\Extraction\ExtractionStatus::Failed)
        <x-alert variant="danger" title="This extraction failed" class="mb-6">
            {{ $extraction->error ?: 'The background worker could not finish this extraction.' }}
            {{-- The raw exception is never shown; see ProcessExtractionJob::failed(). --}}
            @if ($extraction->found_count > 0)
                Any addresses found before the failure are listed below.
            @endif
        </x-alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <x-card class="lg:col-span-1">
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-600">Status</dt>
                    <dd><x-status-badge :status="$extraction->status->value" :label="$extraction->status->label()" /></dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-600">Found</dt>
                    <dd class="font-mono text-slate-800">{{ number_format($extraction->found_count) }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-600">Candidates examined</dt>
                    <dd class="font-mono text-slate-800">{{ number_format($extraction->processed_count) }}</dd>
                </div>
                @if ($extraction->started_at)
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-600">Started</dt>
                        <dd class="text-slate-700">{{ $extraction->started_at->diffForHumans() }}</dd>
                    </div>
                @endif
                @if ($extraction->completed_at)
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-600">Finished</dt>
                        <dd class="text-slate-700">{{ $extraction->completed_at->diffForHumans() }}</dd>
                    </div>
                @endif
            </dl>
        </x-card>

        <x-card class="lg:col-span-2" title="Addresses found">
            @if ($extraction->found_count === 0)
                <x-empty-state
                    title="Nothing found"
                    description="No email addresses were present in the submitted text."
                />
            @else
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="py-2 pr-4">#</th>
                            <th class="py-2">Email</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($results as $result)
                            <tr>
                                <td class="py-2 pr-4 text-slate-400">{{ $result->id }}</td>
                                <td class="py-2 font-mono text-xs text-slate-800">{{ $result->email }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                {{-- Paginated, not the whole set. One extraction of a megabyte of
                     text can hold a great many addresses. --}}
                <div class="mt-4">{{ $results->links() }}</div>
            @endif
        </x-card>
    </div>
</x-layout>

<x-layout>
    <x-slot:title>Your tasks</x-slot:title>

    <x-page-header
        title="Your tasks"
        description="Paste a list of addresses or point at a webpage. Each submission is a task that finds the addresses and then checks whether they can receive email."
    >
        <x-slot:actions>
            <x-button :href="route('extractor.create')">New task</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- Stated, not inferred. A customer whose second paste has not started yet
         will otherwise assume something is broken, and the honest reason —
         one task is checked at a time, because checking a list is slow work —
         is better received than silence. --}}
    @if ($currentTask && ! $currentTask->isTerminal())
        <x-alert variant="info" title="One task is worked on at a time" class="mb-6">
            <strong>{{ $currentTask->displayName() }}</strong> is being
            {{ $currentTask->status === \App\Domain\Extraction\ExtractionStatus::Validating
                ? 'checked now'
                : 'processed now' }}.
            @if ($waitingCount > 0)
                {{ trans_choice('{1} :count task is|[2,*] :count tasks are', $waitingCount, ['count' => $waitingCount]) }}
                waiting behind it.
            @endif
        </x-alert>
    @endif

    @if ($extractions->isEmpty())
        <x-empty-state
            title="No tasks yet"
            description="Paste a list of email addresses, or give us the address of a page that contains them. We will find the addresses and then check which ones can actually receive email."
        >
            <x-slot:actions>
                <x-button :href="route('extractor.create')">New task</x-button>
            </x-slot:actions>
        </x-empty-state>
    @else
        {{-- One card per task rather than a table. A badge has a stage, a bar
             and four counts; a table row cannot carry those without becoming
             unreadable at the width a laptop gives it. --}}
        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($extractions as $extraction)
                @php($taskProgress = $progress[$extraction->id] ?? null)
                <x-card>
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <a href="{{ route('extractor.show', $extraction) }}"
                               class="block truncate font-medium text-slate-900 hover:underline">
                                {{ $extraction->displayName() }}
                            </a>
                            <p class="mt-0.5 truncate text-xs text-slate-500">
                                @if ($extraction->source_type === 'url')
                                    {{ $extraction->source_ref ?: 'Webpage' }}
                                @else
                                    Pasted text
                                @endif
                            </p>
                        </div>
                        <x-status-badge :status="$extraction->status->value"
                                        :label="$extraction->status->stage()" />
                    </div>

                    @if ($extraction->found_count > 0 && $extraction->status !== \App\Domain\Extraction\ExtractionStatus::Queued)
                        <p class="mt-3 text-sm text-slate-700">
                            <span class="font-mono">{{ number_format($extraction->found_count) }}</span>
                            unique {{ $extraction->found_count === 1 ? 'address' : 'addresses' }} found
                        </p>
                    @endif

                    <x-task-progress :progress="$taskProgress" class="mt-2" />

                    @if ($extraction->validation_processed_count > 0)
                        <dl class="mt-3 space-y-1 border-t border-slate-100 pt-3 text-sm">
                            @foreach (\App\Domain\Audience\ValidationStatus::all() as $status)
                                @if ($extraction->{'.'.$status->value.'_count'} > 0)
                                    <div class="flex justify-between gap-3">
                                        <dt class="text-slate-600">{{ $status->label() }}</dt>
                                        <dd class="font-mono text-slate-800">
                                            {{ number_format($extraction->{'.'.$status->value.'_count'}) }}
                                        </dd>
                                    </div>
                                @endif
                            @endforeach
                        </dl>
                    @endif

                    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
                        <span class="text-xs text-slate-500">
                            Started {{ $extraction->created_at?->diffForHumans() }}
                            @if ($extraction->completed_at)
                                &middot; finished {{ $extraction->completed_at->diffForHumans() }}
                            @endif
                        </span>

                        <div class="flex items-center gap-2">
                            <x-button variant="secondary" :href="route('extractor.show', $extraction)">
                                View report
                            </x-button>
                            @if ($extraction->status === \App\Domain\Extraction\ExtractionStatus::Queued)
                                <form method="POST" action="{{ route('extractor.cancel', $extraction) }}">
                                    @csrf
                                    <x-button variant="secondary" type="submit">Cancel</x-button>
                                </form>
                            @endif
                        </div>
                    </div>
                </x-card>
            @endforeach
        </div>

        <div class="mt-6">{{ $extractions->links() }}</div>
    @endif
</x-layout>
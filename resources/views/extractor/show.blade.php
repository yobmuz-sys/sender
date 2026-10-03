<x-layout>
    <x-slot:title>{{ $extraction->displayName() }}</x-slot:title>

    <x-page-header
        :title="$extraction->displayName()"
        :description="'Submitted '.$extraction->created_at?->diffForHumans().'.'"
    >
        <x-slot:actions>
            @if ($extraction->found_count > 0)
                <x-button variant="secondary" :href="route('extractor.download', $extraction)">
                    Download CSV
                </x-button>
            @endif
            <x-button :href="route('extractor.index')">All tasks</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- One alert per stage, each saying what is happening and what, if
         anything, is needed. A badge stuck at "processing" with no explanation
         is the failure this replaces. --}}
    @if ($extraction->status === \App\Domain\Extraction\ExtractionStatus::Queued)
        <x-alert variant="info" title="Waiting to start" class="mb-6">
            This task is queued. It will start when the task ahead of it finishes;
            there is nothing you need to do.
        </x-alert>
    @elseif ($extraction->status === \App\Domain\Extraction\ExtractionStatus::Extracting)
        <x-alert variant="warning" title="Finding addresses" class="mb-6">
            Looking for email addresses in your input. Checking them starts as soon
            as this stage finishes.
        </x-alert>
    @elseif ($extraction->status === \App\Domain\Extraction\ExtractionStatus::Validating)
        <x-alert variant="warning" title="Checking addresses" class="mb-6">
            {{ number_format($extraction->validation_processed_count) }} of
            {{ number_format($extraction->found_count) }} checked so far. This can
            take a while for a large list — each address has to be checked with the
            server that would receive its mail.
        </x-alert>
    @elseif ($extraction->status === \App\Domain\Extraction\ExtractionStatus::Failed)
        @php
            $failureCategory = $extraction->source_type === 'url'
                ? \App\Domain\Extraction\Url\UrlFailureReason::tryFrom((string) $extraction->error)
                : null;
        @endphp
        <x-alert variant="danger" title="This task did not finish" class="mb-6">
            {{ $failureCategory?->label()
                ?? ($extraction->error ?: 'The background worker could not finish this task.') }}

            @if ($failureCategory)
                <p class="mt-1 font-mono text-xs text-red-700">{{ $failureCategory->value }}</p>
            @endif

            @if ($extraction->found_count > 0)
                <p class="mt-1">
                    The {{ number_format($extraction->validation_processed_count) }} addresses that
                    were checked are listed below.
                </p>
            @endif
        </x-alert>
    @endif

    <x-progress-bar :progress="$progress" class="mb-6" />

    {{-- The headline. "Ready to use" rather than "ready to send": there is no
         Send button in this stage, because consent and suppression have not been
         resolved for a list this size, and a button here would be a promise the
         audience layer cannot yet keep. --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="Addresses found"
                :value="number_format($report->extracted)"
                hint="Duplicates within your input were removed." />
        <x-stat label="Addresses checked"
                :value="number_format($report->checked)"
                :hint="$report->wasChecked
                    ? 'Each one was checked with the server that would receive it.'
                    : 'Checking has not run for this task.'" />
        <x-stat label="Likely active"
                :value="number_format($report->ready)"
                hint="The server accepted these addresses." />
        <x-stat label="Not usable"
                :value="number_format(max(0, $report->extracted - $report->ready))"
                hint="Everything else. Open the report below to see why." />
    </div>

    {{-- The classification. Each line says what was observed, in words a person
         who does not know what SMTP is can act on. --}}
    <x-card title="What we found" class="mb-6">
        <x-validation-counts :statuses="\App\Domain\Audience\ValidationStatus::all()"
                             :counts="$report->counts" />

        @if (! $report->wasChecked && $report->extracted > 0)
            <p class="mt-4 text-sm text-slate-600">
                These addresses have not been checked. Only an address the receiving server
                has accepted is counted as likely active, so nothing here can be used for
                anything until a check has run.
            </p>
        @elseif ($report->remaining() > 0)
            <p class="mt-4 text-sm text-slate-600">
                {{ number_format($report->remaining()) }} of
                {{ number_format($report->extracted) }} addresses were not reached before this
                task stopped. They are listed below with no classification rather than being
                counted as inactive.
            </p>
        @endif
    </x-card>

    <x-card title="Addresses">
        {{-- Filter by classification. Unknown is a first-class choice here,
             precisely because "we could not tell" is a different answer from
             "this address is dead" and a customer deserves to be able to look at
             each on its own. --}}
        <div class="mb-4 flex flex-wrap gap-2">
            <x-button variant="secondary"
                      :href="route('extractor.show', $extraction)">All</x-button>
            @foreach (\App\Domain\Audience\ValidationStatus::all() as $status)
                <x-button variant="secondary"
                          :href="route('extractor.show', array_merge(['extraction' => $extraction], $statusFilter === $status ? [] : ['status' => $status->value]))">
                    {{ $status->label() }}
                </x-button>
            @endforeach
        </div>

        @if ($results->isEmpty())
            <x-empty-state
                :title="$report->extracted === 0 ? 'Nothing found' : 'Nothing to show'"
                :description="$report->extracted === 0
                    ? 'No email addresses were present in the submitted input.'
                    : 'No addresses fall into this group. Try another result above to see the rest.'" />
        @else
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="py-2 pr-4">Email</th>
                        <th class="py-2 pr-4">Result</th>
                        <th class="py-2">Why</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($results as $result)
                        <tr>
                            <td class="py-2 pr-4 font-mono text-xs text-slate-800">{{ $result->email }}</td>
                            <td class="py-2 pr-4">
                                @if ($result->validation_status)
                                    <x-status-badge :status="$result->validation_status->tone()"
                                                    :label="$result->validation_status->label()" />
                                @else
                                    {{-- Unreached, not unknown. A row with no
                                         classification has not been looked at,
                                         and saying "unknown" would imply a check
                                         that produced no answer. --}}
                                    <x-status-badge status="unreached" label="Not checked" />
                                @endif
                            </td>
                            <td class="py-2 text-xs text-slate-600">
                                @if ($result->validation_reason)
                                    {{ $result->validation_reason->label() }}
                                    {{-- Technical detail, available but never
                                         leading. --}}
                                    <span class="mt-0.5 block font-mono text-[11px] text-slate-400">
                                        {{ $result->validation_method?->label() }}
                                        @if ($result->contact?->last_enhanced_code)
                                            &middot; {{ $result->contact->last_enhanced_code }}
                                        @endif
                                    </span>
                                @else
                                    This address has not been checked yet.
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="mt-4">{{ $results->links() }}</div>
        @endif
    </x-card>
</x-layout>
<x-layout>
    <x-slot:title>Validation</x-slot:title>

    <x-page-header
        title="Validation"
        description="How many addresses this platform holds, why the ones it could not classify are unclassified, and which tasks did not finish."
    />

    {{-- Four totals, one grouped query each. Contacts are never listed on this
         page: an operator answering "is validation working" needs distribution,
         not nine thousand of one customer's scraped addresses in a browser
         history. --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($statuses as $status)
            <x-stat :label="$status->label()"
                    :value="number_format($totals[$status->value] ?? 0)"
                    :hint="$status->explanation()" />
        @endforeach
    </div>

    <x-card title="Why addresses are unclassified" class="mb-6">
        <p class="mb-4 text-sm text-slate-600">
            This is the operationally important table on the page. A rise in
            &ldquo;this domain accepts any address&rdquo; or &ldquo;the receiving provider does not
            allow verification&rdquo; is a change in the outside world — a provider switching on
            anti-enumeration, or a host that has lost outbound port 25 — and it is not visible
            anywhere else.
        </p>

        @if ($unresolvedReasons === [])
            <x-empty-state
                title="Nothing unclassified"
                description="No address is currently in a state where the platform could not reach a conclusion."
            />
        @else
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="py-2 pr-4">Reason</th>
                        <th class="py-2">Addresses</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($unresolvedReasons as $row)
                        <tr>
                            <td class="py-2 pr-4 text-slate-700">
                                {{ $row['label'] }}
                                <span class="mt-0.5 block font-mono text-[11px] text-slate-400">{{ $row['reason'] }}</span>
                            </td>
                            <td class="py-2 font-mono text-slate-800">{{ number_format($row['count']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-card>

    <x-card title="Tasks that did not finish" class="mb-6">
        @if ($recentFailures->isEmpty())
            <x-empty-state title="No failures" description="No task has failed across any account." />
        @else
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="py-2 pr-4">Account</th>
                        <th class="py-2 pr-4">Task</th>
                        <th class="py-2 pr-4">Checked</th>
                        <th class="py-2">When</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($recentFailures as $task)
                        <tr>
                            <td class="py-2 pr-4 text-slate-700">{{ $task->user?->email ?? '—' }}</td>
                            <td class="py-2 pr-4 text-slate-700">{{ $task->displayName() }}</td>
                            <td class="py-2 pr-4 font-mono text-slate-700">
                                {{ number_format($task->validation_processed_count) }}
                            </td>
                            <td class="py-2 text-slate-500">{{ $task->completed_at?->diffForHumans() ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-card>

    <x-card title="Tasks in progress">
        @if ($inFlight->isEmpty())
            <x-empty-state title="Nothing running" description="No task is queued, extracting or being checked." />
        @else
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="py-2 pr-4">Account</th>
                        <th class="py-2 pr-4">Task</th>
                        <th class="py-2 pr-4">Stage</th>
                        <th class="py-2">Progress</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($inFlight as $task)
                        <tr>
                            <td class="py-2 pr-4 text-slate-700">{{ $task->user?->email ?? '—' }}</td>
                            <td class="py-2 pr-4 text-slate-700">{{ $task->displayName() }}</td>
                            <td class="py-2 pr-4">
                                <x-status-badge :status="$task->status->value" :label="$task->status->stage()" />
                            </td>
                            <td class="py-2 font-mono text-slate-700">
                                {{ number_format($task->validation_processed_count) }} / {{ number_format($task->found_count) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="mt-3 text-xs text-slate-500">
                A task listed here as queued behind another is normal: one task per account is
                processed at a time. See <a href="{{ route('admin.jobs.index') }}" class="underline">Jobs</a>
                for the queue itself.
            </p>
        @endif
    </x-card>
</x-layout>
<x-layout>
    <x-slot:title>Sending health</x-slot:title>

    <x-page-header
        title="Sending health"
        description="What is established about each transport, and what still needs attention. This does not guarantee inbox placement."
    />

    <x-flash />

    @forelse ($accounts as $account)
        @php $report = $reports[$account->getKey()]; @endphp
        <x-card class="mb-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-medium text-slate-900">{{ $account->label }}</h2>
                    <p class="text-sm text-slate-600">
                        {{ $account->provider->label() }} · {{ $account->host }}:{{ $account->port }} ·
                        sends as {{ $account->from_address }}
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <x-status-badge :status="$account->effectiveStatus()->isUsable() ? 'READY' : 'UNKNOWN'"
                                    :label="$account->effectiveStatus()->label()" />
                    <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $report->isTransportHealthy() ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900' }}"
                          title="{{ \App\Domain\Mail\TrafficMode::Transactional->question() }}">
                        SMTP: {{ $report->transportStatus() }}
                    </span>
                    <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $report->isReady(\App\Domain\Mail\TrafficMode::BulkMarketing) ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-900' }}"
                          title="{{ \App\Domain\Mail\TrafficMode::BulkMarketing->question() }}">
                        Bulk marketing: {{ $report->verdict(\App\Domain\Mail\TrafficMode::BulkMarketing) }}
                    </span>
                </div>
            </div>

            {{-- Two verdicts rather than one. A transport with no DMARC record is
                 working and not campaign-ready at the same time, and reporting
                 only the first would invite a customer to start sending. --}}
            @if ($report->isTransportHealthy() && ! $report->isReady(\App\Domain\Mail\TrafficMode::BulkMarketing))
                <div class="mt-4 rounded-md bg-amber-50 p-4">
                    <p class="text-sm font-medium text-amber-900">
                        This transport works, but bulk marketing is not ready yet
                    </p>
                    <p class="mt-1 text-sm text-amber-900">
                        Ordinary transactional messages are not affected. Sending a campaign is, and the gap is in
                        your sending policy rather than in your SMTP account.
                    </p>
                </div>
            @endif

            @if ($report->blockers() !== [])
                <div class="mt-4 rounded-md bg-rose-50 p-4">
                    <p class="text-sm font-medium text-rose-900">Must be resolved before sending</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-rose-900">
                        @foreach ($report->blockers() as $blocker)
                            <li><span class="font-medium">{{ $blocker->check }}:</span> {{ $blocker->detail }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="mt-4">
                <x-readiness-findings :report="$report" />
            </div>
        </x-card>
    @empty
        <x-empty-state
            title="No transports to assess"
            description="Add an SMTP account and this page will report what can be established about it."
        />
        <div class="mt-6">
            <a href="{{ route('account.smtp.create') }}"
               class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                Add a transport
            </a>
        </div>
    @endforelse

    <div class="mt-8">
        <x-readiness-limits :limits="$limits" />
    </div>
</x-layout>
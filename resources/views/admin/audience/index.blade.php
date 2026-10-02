<x-layout>
    <x-slot:title>Audience</x-slot:title>

    <x-page-header
        title="Audience"
        description="The contacts this platform holds across every account, and how they are classified."
    />

    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="Contacts" :value="number_format($contacts)" hint="Canonical addresses across all accounts." />
        <x-stat label="Accounts with contacts" :value="number_format($tenants)" />
        <x-stat label="Lists" :value="number_format($lists)" />
        <x-stat label="Tasks submitted" :value="number_format($tasks)" />
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-card title="Contacts by check result">
            <dl class="space-y-3 text-sm">
                @foreach ($statuses as $status)
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-600">{{ $status->label() }}</dt>
                        <dd class="font-mono text-slate-800">
                            {{ number_format($statusTotals[$status->value] ?? 0) }}
                        </dd>
                    </div>
                @endforeach
            </dl>
            <p class="mt-4 text-xs text-slate-500">
                Only &ldquo;likely active&rdquo; is usable. Every other state is excluded by default,
                and an excluded address is not an error &mdash; it is the platform declining to
                claim something it cannot support.
            </p>
        </x-card>

        <x-card title="Tasks by stage">
            <dl class="space-y-3 text-sm">
                @foreach ($taskStages as $stage)
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-600">{{ $stage->label() }}</dt>
                        <dd class="font-mono text-slate-800">{{ number_format($taskTotals[$stage->value] ?? 0) }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-card>

        <x-card title="Suppressed, by reason">
            <dl class="space-y-3 text-sm">
                @foreach ($suppressionReasons as $reason)
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-600">{{ $reason->label() }}</dt>
                        <dd class="font-mono text-slate-800">
                            {{ number_format($suppressionTotals[$reason->value] ?? 0) }}
                        </dd>
                    </div>
                @endforeach
            </dl>
            <p class="mt-4 text-xs text-slate-500">
                An unsubscribe and a complaint cannot be lifted, by any operator action, through
                this platform.
            </p>
        </x-card>

        <x-card title="Consent">
            <p class="text-sm text-slate-600">{{ $consentNote }}</p>
            <p class="mt-3 text-xs text-slate-500">
                Consent is recorded as evidence rather than a flag, so &ldquo;somebody pasted this
                list&rdquo; and &ldquo;this person signed up&rdquo; are distinguishable. Neither is
                invented by an import.
            </p>
        </x-card>
    </div>

    <x-alert variant="info" title="Contact data is not shown here" class="mt-6">
        This page reports totals only. Individual addresses belong to the account that
        submitted them and are reachable from that account's own task report, where the
        customer has already seen them.
    </x-alert>
</x-layout>
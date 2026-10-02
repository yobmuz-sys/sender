<x-layout>
    <x-slot:title>Sending health</x-slot:title>

    <x-page-header
        title="Sending health"
        description="Aggregate transport status across tenants. This reports configuration state, not inbox placement."
    />

    <x-flash />

    <div class="grid gap-4 sm:grid-cols-3 lg:grid-cols-5">
        <x-stat label="Transports" :value="$total" />
        @foreach ($statuses as $status)
            <x-stat :label="$status['label']" :value="$status['count']" />
        @endforeach
    </div>

    <x-card class="mt-6" title="Recent failures">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                        <th class="py-2 pr-4">Account</th>
                        <th class="py-2 pr-4">User</th>
                        <th class="py-2 pr-4">Endpoint</th>
                        <th class="py-2 pr-4">Category</th>
                        <th class="py-2">When</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recentFailures as $failure)
                        <tr class="border-b border-slate-100 last:border-0">
                            <td class="py-2 pr-4">
                                <a href="{{ route('admin.smtp.accounts.show', $failure) }}"
                                   class="font-medium text-slate-900 hover:underline">{{ $failure->label }}</a>
                            </td>
                            <td class="py-2 pr-4 text-slate-700">{{ $failure->user?->email ?? '—' }}</td>
                            <td class="py-2 pr-4 text-slate-700">{{ $failure->host }}:{{ $failure->port }}</td>
                            <td class="py-2 pr-4 text-slate-700">
                                {{ \App\Domain\Mail\SmtpFailureReason::tryFrom($failure->last_failure_category)?->label() ?? $failure->last_failure_category }}
                            </td>
                            <td class="py-2 text-slate-700">{{ $failure->last_failure_at?->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-6 text-center text-slate-500">
                                No transport failures have been recorded.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <div class="mt-6">
        <x-readiness-limits :limits="[
            'proves' => [
                'Whether each transport is configured, encrypted and verified.',
                'Whether its server accepted a connection from those credentials.',
            ],
            'cannot_prove' => [
                'Whether any message reached a recipient.',
                'Whether a message was placed in an inbox rather than spam.',
                'The reputation of the final sending infrastructure, which this platform neither controls nor can repair.',
            ],
        ]" />
    </div>
</x-layout>
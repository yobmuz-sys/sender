<x-layout>
    <x-slot:title>Suppression</x-slot:title>

    <x-page-header
        title="Suppression"
        description="Addresses across every account that must never be contacted again, and the reason each was recorded."
    />

    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach (\App\Domain\Audience\SuppressionReason::cases() as $reason)
            @if (($counts[$reason->value] ?? 0) > 0)
                <x-stat :label="$reason->label()"
                        :value="number_format($counts[$reason->value])"
                        :hint="$reason->explanation()" />
            @endif
        @endforeach
    </div>

    <x-card title="Suppress an address" class="mb-6">
        <p class="mb-4 text-sm text-slate-600">
            Use this when a bounce report or a complaint feed tells you an address must stop.
            The address is removed from every list and every future campaign for that account.
        </p>
        <form method="POST" action="{{ route('admin.suppression.store') }}" class="max-w-2xl space-y-4">
            @csrf
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="user_id" value="Account id" />
                    <x-text-input id="user_id" name="user_id" type="number" class="mt-1 block w-full"
                                  :value="old('user_id')" required />
                    <x-input-error class="mt-1" :messages="$errors->get('user_id')" />
                </div>
                <div>
                    <x-input-label for="reason" value="Reason" />
                    <select id="reason" name="reason" required
                            class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                        @foreach (\App\Domain\Audience\SuppressionReason::cases() as $reason)
                            <option value="{{ $reason->value }}">{{ $reason->label() }}</option>
                        @endforeach
                    </select>
                    <x-input-error class="mt-1" :messages="$errors->get('reason')" />
                </div>
            </div>
            <div>
                <x-input-label for="email" value="Email address" />
                <x-text-input id="email" name="email" type="text" class="mt-1 block w-full"
                              :value="old('email')" required />
                <x-input-error class="mt-1" :messages="$errors->get('email')" />
            </div>
            <div>
                <x-input-label for="note" value="Note" />
                <x-text-input id="note" name="note" type="text" class="mt-1 block w-full"
                              :value="old('note')" />
                <x-input-error class="mt-1" :messages="$errors->get('note')" />
                <p class="mt-1 text-xs text-slate-500">Optional. Never paste an SMTP response here — they quote addresses.</p>
            </div>
            <x-primary-button>Suppress address</x-primary-button>
        </form>
    </x-card>

    <x-card title="Suppressed addresses">
        @if ($suppressions->isEmpty())
            <x-empty-state
                title="Nothing suppressed"
                description="No address across any account is currently suppressed."
            />
        @else
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="py-2 pr-4">Account</th>
                        <th class="py-2 pr-4">Email</th>
                        <th class="py-2 pr-4">Reason</th>
                        <th class="py-2 pr-4">Added</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($suppressions as $suppression)
                        <tr>
                            <td class="py-2 pr-4 text-slate-700">{{ $suppression->user?->email ?? '—' }}</td>
                            <td class="py-2 pr-4 font-mono text-xs text-slate-800">
                                {{ $suppression->contact?->email ?? '—' }}
                            </td>
                            <td class="py-2 pr-4">
                                <x-status-badge :status="$suppression->reason->tone()"
                                                :label="$suppression->reason->label()" />
                            </td>
                            <td class="py-2 pr-4 text-slate-500">
                                {{ $suppression->created_at?->diffForHumans() ?? '—' }}
                            </td>
                            <td class="py-2 text-right">
                                @if ($suppression->reason->canBeCleared())
                                    <form method="POST" action="{{ route('admin.suppression.destroy', $suppression) }}">
                                        @csrf
                                        @method('DELETE')
                                        <x-button variant="secondary" type="submit">Lift</x-button>
                                    </form>
                                @else
                                    <span class="text-xs text-slate-400">Cannot be lifted</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="mt-4">{{ $suppressions->links() }}</div>
        @endif
    </x-card>
</x-layout>
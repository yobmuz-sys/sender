<x-layout>
    <x-slot:title>Never contact</x-slot:title>

    <x-page-header
        title="Never contact"
        description="These addresses will not be contacted by any of your lists, any list you import later, or any campaign. Removing a contact from a list does not change this."
    />

    @if ($total === 0)
        <x-empty-state
            title="Nobody is on this list"
            description="When somebody unsubscribes, bounces, or reports a message as spam, the address is recorded here and stays here — even if you import the same list again."
        />
    @else
        <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach (\App\Domain\Audience\SuppressionReason::cases() as $reason)
                @if (($counts[$reason->value] ?? 0) > 0)
                    <x-stat :label="$reason->label()"
                            :value="number_format($counts[$reason->value])"
                            :hint="$reason->explanation()" />
                @endif
            @endforeach
        </div>

        <x-card>
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="py-2 pr-4">Email</th>
                        <th class="py-2 pr-4">Reason</th>
                        <th class="py-2 pr-4">Added</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($suppressions as $suppression)
                        <tr>
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
                                    {{-- Only rendered where the domain permits it. The
                                         domain refuses as well; a hidden button is
                                         good manners, not the safeguard. --}}
                                    <form method="POST"
                                          action="{{ route('suppression.destroy', $suppression) }}">
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
        </x-card>
    @endif
</x-layout>
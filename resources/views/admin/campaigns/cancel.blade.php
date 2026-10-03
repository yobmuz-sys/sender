<x-layout>
    <x-slot:title>Cancel {{ $campaign->name }}?</x-slot:title>

    {{--
        Confirmation before an irreversible action, from either side of the platform.

        Cancelling is the one thing about a campaign that cannot be undone, and an
        operator is one click away from stopping somebody else's send. So the page
        states what cancelling will actually do to the numbers: the messages already
        accepted by a server cannot be recalled, and everyone still waiting will be
        recorded as skipped and never contacted. It is the same page the owner sees,
        with the owner named, because the arithmetic is identical whoever clicks.
    --}}
    <x-page-header title="Cancel this campaign?"
                    description="Nothing here can be undone afterwards.">
        <x-slot:actions>
            <x-button variant="secondary" :href="route('admin.campaigns.show', $campaign)">Keep sending</x-button>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-6">
        <x-alert variant="danger" title="This is not reversible">
            <p>{{ $campaign->name }} belongs to {{ $owner?->name ?? 'a deleted account' }}
                ({{ $owner?->email ?? 'no email on record' }}).</p>
        </x-alert>
    </div>

    <x-card class="mb-6">
        <dl class="grid gap-4 sm:grid-cols-3">
            <x-stat label="Already accepted by a server" :value="number_format($sent)"
                    hint="Sent. Cancelling cannot recall these." />

            <x-stat label="Still waiting" :value="number_format($outstanding)"
                    hint="Queued or being submitted. These will not be sent." />

            <x-stat label="Sending account"
                    :value="$campaign->smtpAccount?->label ?? 'None'"
                    hint="Unchanged. Cancelling a campaign never touches a transport." />
        </dl>
    </x-card>

    <x-card title="What happens when you confirm">
        <ul class="list-disc space-y-2 pl-5 text-sm text-slate-700">
            <li>Every recipient still waiting is recorded as skipped, so the campaign's figures still account for all of them.</li>
            <li>The campaign stops. Its worker will not pick it up again.</li>
            <li>The message and audience it froze at launch are kept, so the record of what it was going to send survives.</li>
            <li>The sending account is untouched: it stays configured, verified or not, for whatever it is used for next.</li>
        </ul>

        <p class="mt-4 text-sm text-slate-600">
            If the reason for cancelling is that the transport is failing, fix or replace the account instead — a new
            campaign from the same audience is the supported way to send again, and this platform will not move a
            campaign to a different account on request.
        </p>

        <form method="POST" action="{{ route('admin.campaigns.confirm', $campaign) }}" class="mt-6 flex gap-3">
            @csrf
            <x-button variant="danger" type="submit">Cancel this campaign</x-button>
            <x-button variant="secondary" :href="route('admin.campaigns.show', $campaign)">Keep sending</x-button>
        </form>
    </x-card>
</x-layout>
@use('App\Domain\Campaigns\CampaignRecipientStatus')

<x-layout>
    <x-slot:title>Cancel {{ $campaign->name }}</x-slot:title>

    <x-page-header
        :title="'Cancel '.$campaign->name"
        description="Cancelling stops this campaign for good. Read what it costs before you do it."
    />

    <div class="max-w-2xl space-y-6">
        <x-alert variant="warning" title="This cannot be undone">
            There is no un-cancel. The only way to send this message to these people afterwards is to create
            another campaign, which freezes a new audience and starts again from the first recipient.
        </x-alert>

        <x-card title="What cancelling stops">
            <dl class="grid gap-3 sm:grid-cols-2">
                <div class="rounded-md bg-slate-50 px-3 py-2 ring-1 ring-inset ring-slate-200">
                    <dt class="text-xs text-slate-600">Waiting to be sent</dt>
                    <dd class="mt-0.5 text-lg font-semibold text-slate-900">
                        {{ number_format($counts[CampaignRecipientStatus::Queued->value] ?? 0) }}
                    </dd>
                    <p class="text-xs text-slate-500">These people will not be contacted from this campaign.</p>
                </div>

                <div class="rounded-md bg-slate-50 px-3 py-2 ring-1 ring-inset ring-slate-200">
                    <dt class="text-xs text-slate-600">Already sent</dt>
                    <dd class="mt-0.5 text-lg font-semibold text-slate-900">
                        {{ number_format($counts[CampaignRecipientStatus::Sent->value] ?? 0) }}
                    </dd>
                    <p class="text-xs text-slate-500">
                        A message a server already accepted cannot be recalled, and cancelling does not pretend
                        otherwise.
                    </p>
                </div>
            </dl>

            @if (($counts[CampaignRecipientStatus::Sent->value] ?? 0) > 0)
                <p class="mt-3 text-sm text-slate-700">
                    Anyone who has already received this campaign, or who receives a message from it later because
                    their server is still retrying, will not be contacted by the replacement campaign either. That is
                    the suppression list working, and it is not something this page can override.
                </p>
            @endif
        </x-card>

        <div class="flex flex-wrap items-center gap-3">
            <form method="POST" action="{{ route('campaigns.cancel', $campaign) }}">
                @csrf
                <x-button variant="danger" type="submit">Cancel this campaign</x-button>
            </form>

            <x-button variant="secondary" :href="route('campaigns.show', $campaign)">Keep it</x-button>
        </div>
    </div>
</x-layout>
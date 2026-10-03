@use('App\Domain\Campaigns\CampaignStatus')

<x-layout>
    <x-slot:title>Edit {{ $campaign->name }}</x-slot:title>

    <x-page-header
        :title="'Edit '.$campaign->name"
        description="This campaign has not started yet, so what it will send and who it will send to can still be changed."
    >
        <x-slot:actions>
            <x-button :href="route('campaigns.show', $campaign)">Back to campaign</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($campaign->status === CampaignStatus::Scheduled)
        <x-alert variant="warning" class="mb-6">
            This campaign is scheduled. Saving changes returns it to a draft, because the audience snapshot is
            taken from the list at the moment it starts.
        </x-alert>
    @endif

    @include('campaigns.partials.form')
</x-layout>
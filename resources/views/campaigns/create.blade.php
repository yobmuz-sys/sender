<x-layout>
    <x-slot:title>New campaign</x-slot:title>

    <x-page-header
        title="New campaign"
        description="Prepare a message for a list you have already checked. Nothing is sent from this page: saving creates a draft, and starting freezes the message and the audience before the worker sends anything."
    >
        <x-slot:actions>
            <x-button :href="route('campaigns.index')">All campaigns</x-button>
        </x-slot:actions>
    </x-page-header>

    @include('campaigns.partials.form')
</x-layout>
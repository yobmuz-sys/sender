@php
    /**
     * The actions available on a campaign, in the order they should be offered.
     *
     * One partial, used by the list and by the campaign's own page, because the two
     * showing different buttons for the same state is how a customer ends up with
     * a Resume button on a campaign the platform would refuse to resume.
     *
     * `$summary` is a {@see CampaignSummary}. On the campaign page it is built from
     * the stored counts rather than from the list query, so the buttons are right
     * even though the figures came from somewhere else.
     */
@endphp

<div class="flex flex-wrap items-center gap-2">
    @foreach ($summary->actions() as $action)
        @if ($action->isImmediate())
            <form method="POST" action="{{ route($action->routeName(), $campaign) }}">
                @csrf
                <x-button variant="{{ $action->variant() }}" type="submit">{{ $action->label() }}</x-button>
            </form>
        @else
            <x-button variant="{{ $action->variant() }}" :href="route($action->routeName(), $campaign)">
                {{ $action->label() }}
            </x-button>
        @endif
    @endforeach
</div>
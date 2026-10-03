{{--
    The interventions available on one campaign, read off its state.

    Rendered from {@see \App\Domain\Campaigns\Operator\CampaignOperatorSummary::actions()}
    so this table, the admin campaign page and the campaign's own legality rules
    cannot drift apart: an operator is never offered a button the endpoint would
    refuse, and never denied one that would have worked.

    Cancel is a link to a confirmation page rather than a button in a column of
    twenty-five rows. It is the only action here that cannot be undone, and an
    operator stopping somebody else's send to eleven thousand people should have to
    read what that costs before they do it — exactly as the customer's own page does.
--}}
@foreach ($summary->actions() as $action)
    @if ($action->value === 'cancel')
        <a href="{{ route('admin.campaigns.cancel', $summary->campaign) }}"
           class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium text-rose-700 hover:bg-rose-50">
            {{ $action->label() }}
        </a>
    @else
        <form method="POST" action="{{ route('admin.campaigns.'.$action->value, $summary->campaign) }}"
              class="inline-flex">
            @csrf
            <button type="submit"
                    class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100">
                {{ $action->label() }}
            </button>
        </form>
    @endif
@endforeach
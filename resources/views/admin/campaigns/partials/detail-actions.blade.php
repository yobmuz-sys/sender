{{--
    The interventions an operator has on one campaign.

    Read from the operator summary's own action list, which is derived from the
    campaign's state — the same source the endpoint asks again before doing anything.
    Administrative authority is not a second opinion about legality: `campaigns.pause`
    decides *who* may ask, and `allowsPause()` decides *whether it is allowed*.

    There is no "start", no "edit" and no "send through another account" here, and
    their absence is the design. Launching runs a preflight for a named owner,
    rewriting a frozen campaign is not a diagnostic act, and moving a failing send to
    a different transport is the evasion CampaignStatus::Failed exists to prevent.
--}}
@php($intervenable = $detail->operator->actions())

@if ($intervenable === [])
    <span class="text-xs text-slate-500">Nothing to do here.</span>
@else
    @foreach ($intervenable as $action)
        @if ($action->value === 'cancel')
            <a href="{{ route('admin.campaigns.cancel', $detail->campaign) }}"
               class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium text-rose-700 ring-1 ring-inset ring-rose-300 hover:bg-rose-50">
                {{ $action->label() }}
            </a>
        @else
            <form method="POST" action="{{ route('admin.campaigns.'.$action->value, $detail->campaign) }}"
                  class="inline-flex">
                @csrf
                <button type="submit"
                        class="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    {{ $action->label() }}
                </button>
            </form>
        @endif
    @endforeach
@endif
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Mail\SmtpAccount;
use App\Domain\Mail\SmtpAccountStatus;
use App\Domain\Users\Permission;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Aggregate sending health across every tenant.
 *
 * Aggregates the account metadata only — provider, status, verification age,
 * failure category — because that is the whole of what an administrator can
 * honestly be told. The page states the limit of that in its own heading: this is
 * a readiness report, not a measurement of deliverability.
 *
 * The findings themselves are computed per account on each tenant's own page.
 * Evaluating every account here would mean a DNS lookup per row, turning one
 * page load into dozens of resolver queries against a shared host.
 */
class DeliverabilityController extends Controller
{
    public function __invoke(Request $request): View
    {
        abort_unless($request->user()?->can(Permission::DELIVERABILITY_VIEW) ?? false, 403);

        $counts = SmtpAccount::query()
            ->get()
            ->groupBy(static fn (SmtpAccount $account): string => $account->effectiveStatus()->value)
            ->map(static fn ($group): int => $group->count());

        $statuses = [];

        foreach (SmtpAccountStatus::cases() as $status) {
            $statuses[$status->value] = [
                'label' => $status->label(),
                'count' => (int) ($counts[$status->value] ?? 0),
            ];
        }

        $recentFailures = SmtpAccount::query()
            ->whereNotNull('last_failure_category')
            ->with('user')
            ->orderByDesc('last_failure_at')
            ->limit(25)
            ->get();

        return view('admin.deliverability.index', [
            'statuses' => $statuses,
            'total' => array_sum(array_column($statuses, 'count')),
            'recentFailures' => $recentFailures,
        ]);
    }
}

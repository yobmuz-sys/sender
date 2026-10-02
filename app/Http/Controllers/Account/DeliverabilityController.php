<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Mail\DeliveryReadiness;
use App\Domain\Mail\SmtpAccount;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The tenant's own sending health.
 *
 * Shows evidence and what to do about the gaps. It deliberately does not present
 * a number: the platform can observe what a tenant configured and what their
 * domain publishes, and nothing about how a recipient's mail provider will treat
 * the message. A score would imply the second.
 */
class DeliverabilityController extends Controller
{
    public function __construct(private readonly DeliveryReadiness $readiness) {}

    public function __invoke(Request $request): View
    {
        $accounts = SmtpAccount::query()
            ->ownedBy($request->user()->getKey())
            ->orderBy('label')
            ->get();

        $reports = [];

        foreach ($accounts as $account) {
            $reports[$account->getKey()] = $this->readiness->for($account);
        }

        return view('account.deliverability.index', [
            'accounts' => $accounts,
            'reports' => $reports,
            'limits' => $reports === [] ? [] : reset($reports)->limits(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Audience;

use App\Domain\Audience\SuppressionList;
use App\Domain\Audience\SuppressionReason;
use App\Http\Controllers\Controller;
use App\Models\Suppression;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The tenant's suppression list, and what it means that it exists.
 *
 * This page is the visible half of a guarantee the customer would otherwise have
 * to take on trust: whatever is on this list is not contacted, by any list, by
 * any future import, or by any campaign. The guarantee lives in a tenant-scoped
 * unique constraint rather than in anything on this screen, and the screen's job
 * is to make it legible — an invisible suppression list is indistinguishable from
 * no suppression list at all.
 *
 * Clearing is deliberately restricted. An unsubscribe and a spam complaint were
 * caused by the recipient, and {@see SuppressionReason::canBeCleared()}
 * refuses to lift either. The button is not rendered for them, the domain refuses
 * them if the request is made anyway, and the reason a row cannot be cleared is
 * shown next to the row that cannot be. Three layers is not paranoia here — it is
 * the difference between a suppression list and a suggestion.
 */
class SuppressionController extends Controller
{
    /**
     * Rows per page.
     */
    private const PER_PAGE = 50;

    public function index(Request $request): View
    {
        $suppressions = Suppression::query()
            ->where('user_id', $request->user()->id)
            // The contact is needed for the address, and every suppression page
            // renders it, so eager loading is correct rather than a shortcut.
            ->with('contact')
            ->latest('created_at')
            ->latest('id')
            ->paginate(self::PER_PAGE);

        $counts = Suppression::query()
            ->where('user_id', $request->user()->id)
            ->selectRaw('reason, count(*) as aggregate')
            ->groupBy('reason')
            ->pluck('aggregate', 'reason');

        return view('audience.suppression.index', [
            'suppressions' => $suppressions,
            'counts' => $counts,
            'total' => (int) $counts->sum(),
        ]);
    }

    /**
     * Lift a suppression the platform is permitted to lift.
     *
     * Returns the same 404 for another tenant's row as for a row that does not
     * exist. A 403 would confirm it exists, and the identifiers are sequential.
     */
    public function destroy(Request $request, SuppressionList $suppressions, mixed $suppression = null): RedirectResponse
    {
        if (! is_numeric((string) $suppression)) {
            abort(404);
        }

        $row = Suppression::query()
            ->whereKey((int) $suppression)
            ->where('user_id', $request->user()->id)
            ->first();

        abort_if($row === null, 404);

        if (! $suppressions->clear($row)) {
            return back()->with(
                'error',
                $row->reason->label().' cannot be lifted. '
                    .'This person asked not to be contacted, or reported a message as spam.',
            );
        }

        return back()->with('status', 'Suppression lifted. The address will be checked again before it is contacted.');
    }
}

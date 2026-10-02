<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Audience\EmailAddress;
use App\Domain\Audience\SuppressionList;
use App\Domain\Audience\SuppressionReason;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Suppression;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Suppression across tenants, for an operator.
 *
 * The one place an operator can act on somebody else's audience, and it is kept
 * narrow on purpose. Suppressing is the cheap operation — it removes a recipient
 * from every list and every future campaign, and can do no harm. Lifting is the
 * expensive one, and it is refused for the two reasons a recipient caused.
 *
 * An operator therefore can suppress an address for a tenant — which is what they
 * are here for, when a bounce report or a complaint feed tells them something
 * must stop — and cannot reinstate one that a person unsubscribed from or
 * complained about. Those are the recipient's decisions, and no administrative
 * privilege here revokes them.
 *
 * Addresses are shown. That is the difference between this page and the audience
 * overview, and it is justified: an operator acting on a specific suppression has
 * to know which one, and the page is permission-gated at `suppression.manage`
 * rather than `suppression.view`.
 */
class SuppressionAdminController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): View
    {
        return view('admin.suppression.index', [
            'suppressions' => Suppression::query()
                ->with(['contact', 'user'])
                ->latest('created_at')
                ->latest('id')
                ->paginate(self::PER_PAGE),
            'counts' => $this->counts(),
        ]);
    }

    /**
     * Suppress one of a tenant's addresses.
     *
     * Resolves the address from a tenant and a canonical email rather than from
     * a contact id chosen by the caller, so the operator names the address they
     * mean rather than an identifier they have to have looked up. The contact is
     * created if it does not exist, because suppressing something the platform
     * has never stored is still a meaningful instruction — "never contact this
     * address" does not require the platform to know anything else about it.
     */
    public function store(
        Request $request,
        SuppressionList $suppressions,
    ): RedirectResponse {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'email' => ['required', 'string', 'max:320'],
            'reason' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $key = EmailAddress::key($validated['email']);

        $contact = Contact::query()->firstOrCreate(
            [
                'user_id' => (int) $validated['user_id'],
                'normalized_email' => $key,
            ],
            ['email' => EmailAddress::display($validated['email'])],
        );

        $suppressions->suppress(
            $contact,
            SuppressionReason::from($validated['reason']),
            source: 'admin',
            note: $validated['note'] ?? null,
        );

        return back()->with('status', 'Address suppressed. It will not be contacted by any list or campaign.');
    }

    /**
     * Lift a suppression, where the reason permits it.
     */
    public function destroy(Request $request, mixed $suppression, SuppressionList $suppressions): RedirectResponse
    {
        if (! is_numeric((string) $suppression)) {
            abort(404);
        }

        $row = Suppression::query()->find((int) $suppression);

        abort_if($row === null, 404);

        if (! $suppressions->clear($row)) {
            return back()->with(
                'error',
                $row->reason->label().' cannot be lifted. The recipient asked not to be contacted, or reported a message as spam.',
            );
        }

        return back()->with('status', 'Suppression lifted.');
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $counts = [];

        foreach (SuppressionReason::cases() as $reason) {
            $counts[$reason->value] = 0;
        }

        foreach (
            Suppression::query()
                ->selectRaw('reason, count(*) as aggregate')
                ->groupBy('reason')
                ->pluck('aggregate', 'reason') as $reason => $aggregate
        ) {
            if (array_key_exists((string) $reason, $counts)) {
                $counts[(string) $reason] = (int) $aggregate;
            }
        }

        return $counts;
    }
}

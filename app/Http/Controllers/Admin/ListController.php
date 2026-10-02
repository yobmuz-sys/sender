<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactList;
use Illuminate\View\View;

/**
 * Lists across tenants, read-only.
 *
 * Two questions, and nothing else: how many lists exist platform-wide, and which
 * account owns each. Those are the questions a support agent needs when a
 * customer says "my leads list has gone".
 *
 * Read-only by construction. `lists.manage` is the permission that lets an
 * operator *edit* a tenant's audience, and no route here uses it — renaming
 * somebody's list or deleting their memberships from the administration area
 * would be an intervention in a customer's own data with no audit trail and no
 * context for why. It exists on the account's own pages, where the person acting
 * is the owner.
 */
class ListController extends Controller
{
    public function index(): View
    {
        return view('admin.lists.index', [
            'lists' => ContactList::query()
                ->withCount('memberships')
                // The owner is named rather than linked: an operator reading this
                // list needs to know *whose* list it is, and a full user record
                // for each row would be data this page has no reason to hold.
                ->leftJoin('users', 'users.id', '=', 'contact_lists.user_id')
                ->select('contact_lists.*', 'users.name as owner_name', 'users.email as owner_email')
                ->orderBy('users.name')
                ->orderBy('contact_lists.name')
                ->paginate(50),
            'total' => ContactList::query()->count(),
            'owners' => ContactList::query()->distinct()->count('user_id'),
        ]);
    }
}

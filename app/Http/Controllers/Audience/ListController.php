<?php

declare(strict_types=1);

namespace App\Http\Controllers\Audience;

use App\Domain\Audience\AudienceEligibility;
use App\Domain\Audience\ConsentLedger;
use App\Domain\Audience\EmailAddress;
use App\Domain\Audience\SuppressionList;
use App\Domain\Audience\ValidationMethod;
use App\Domain\Audience\ValidationReason;
use App\Domain\Audience\ValidationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Audience\StoreListRequest;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\ContactListMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Named collections of a tenant's contacts.
 *
 * A list holds contacts; it never holds copies of them. Every membership is a
 * row pointing at the one canonical contact, so a validation result, a consent
 * record and a suppression recorded once apply to every list the contact is on.
 * The alternative — a contact per list — is the design that makes "did I
 * unsubscribe this person?" answerable only by checking every copy.
 *
 * Adding contacts does not validate them. It cannot: validation is network work
 * over thousands of addresses and belongs in the bounded worker, not in a form
 * submission. So a list fills with contacts that are `UNKNOWN` and the page says
 * so, rather than pretending a list is ready before anything has checked it.
 *
 * Ownership is enforced by scoping every lookup, and another tenant's list is a
 * 404 rather than a 403 — a 403 confirms the record exists, and the identifiers
 * are sequential.
 */
class ListController extends Controller
{
    /**
     * Memberships shown per page.
     */
    private const MEMBERS_PER_PAGE = 50;

    public function index(Request $request): View
    {
        return view('audience.lists.index', [
            'lists' => ContactList::query()
                ->where('user_id', $request->user()->id)
                ->withCount('memberships')
                ->orderBy('name')
                ->paginate(25),
        ]);
    }

    public function create(): View
    {
        // The same template renders the create and rename forms, and it reads
        // `$list` unconditionally. Passing null rather than omitting the key
        // keeps the view free of an `isset` guard that could drift out of step
        // with the controller.
        return view('audience.lists.create', [
            'list' => null,
        ]);
    }

    public function store(StoreListRequest $request): RedirectResponse
    {
        $list = ContactList::query()->create([
            'user_id' => $request->user()->id,
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
        ]);

        return redirect()->route('lists.show', $list)
            ->with('status', 'List created.');
    }

    /**
     * One list, its members, and what is actually sendable from it.
     *
     * The two counts are shown together and diverge the moment anything is
     * excluded. A list of nine thousand showing "9,000 contacts" and nothing else
     * reads as ready to send; it is not, and the page's job is to say so before
     * the customer finds out from a bounce.
     */
    public function show(Request $request, AudienceEligibility $eligibility, mixed $list = null): View
    {
        $record = $this->owned($request, $list);

        return view('audience.lists.show', [
            'list' => $record,
            'members' => $record->memberships()
                ->with('contact')
                ->orderBy('id')
                ->paginate(self::MEMBERS_PER_PAGE),
            'eligibility' => $eligibility,
            'consents' => app(ConsentLedger::class),
            'suppressions' => app(SuppressionList::class),
        ]);
    }

    public function edit(Request $request, mixed $list = null): View
    {
        return view('audience.lists.create', [
            'list' => $this->owned($request, $list),
        ]);
    }

    public function update(StoreListRequest $request, mixed $list = null): RedirectResponse
    {
        $record = $this->owned($request, $list);

        $record->fill([
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
        ])->save();

        return redirect()->route('lists.show', $record)
            ->with('status', 'List updated.');
    }

    /**
     * Delete a list.
     *
     * The memberships go with it; the contacts do not. A contact is a person the
     * tenant holds, and deleting a bucket must not delete the person — least of
     * all a contact who is suppressed, whose suppression has to survive
     * everything, including their account tidying up their own lists.
     */
    public function destroy(Request $request, mixed $list = null): RedirectResponse
    {
        $this->owned($request, $list)->delete();

        return redirect()->route('lists.index')
            ->with('status', 'List deleted. The contacts on it were kept.');
    }

    /**
     * Add addresses to a list, creating canonical contacts where needed.
     *
     * Batched and constraint-first. Contacts are inserted with `insertOrIgnore`
     * against `unique(user_id, normalized_email)` and memberships with the same,
     * so a paste that repeats an address — or a contact already on the list —
     * produces one row and not two. Nothing asks "does this exist" first, because
     * that window is where two concurrent pastes both see nothing.
     */
    public function addContacts(Request $request, mixed $list = null): RedirectResponse
    {
        $record = $this->owned($request, $list);

        $validated = $request->validate([
            'addresses' => [
                'required',
                'string',
                'max:'.(int) config('sender.deployment_limits.max_text_input_bytes', 1048576),
            ],
        ]);

        $keys = $this->canonicalKeys((string) $validated['addresses']);

        if ($keys === []) {
            return back()->with('error', 'No email addresses were found in that text.');
        }

        $this->syncContacts($record, $keys);

        return back()->with(
            'status',
            count($keys).' addresses added. Checking them happens in the background and is reported on each task.',
        );
    }

    /**
     * Remove one contact from one list.
     *
     * Removes the membership only. Nothing about the contact changes: it keeps
     * its validation, its consent history and — above all — its suppression. A
     * recipient who unsubscribed must not become contactable because the customer
     * tidied a list up.
     */
    public function removeContact(Request $request, mixed $list = null, mixed $contact = null): RedirectResponse
    {
        $record = $this->owned($request, $list);

        $contactId = (int) $contact;

        $membership = ContactListMembership::query()
            ->where('list_id', $record->id)
            ->where('contact_id', $contactId)
            ->where('user_id', $record->user_id)
            ->first();

        abort_if($membership === null, 404);

        $membership->delete();

        return back()->with('status', 'Removed from this list.');
    }

    /**
     * Distinct canonical addresses from a block of pasted text.
     *
     * Split on every character that ends an address in the formats people
     * actually paste: newlines, commas, semicolons. Order is preserved so the
     * "added N" message matches what the customer sees.
     *
     * @return list<string>
     */
    private function canonicalKeys(string $text): array
    {
        $seen = [];
        $keys = [];

        foreach (preg_split('/[\r\n,;]+/', $text) ?: [] as $candidate) {
            $key = EmailAddress::key($candidate);

            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * Create the missing contacts and attach every key to the list.
     *
     * @param  list<string>  $keys
     */
    private function syncContacts(ContactList $list, array $keys): void
    {
        $now = now();

        $rows = array_map(static fn (string $key): array => [
            'user_id' => $list->user_id,
            'email' => $key,
            'normalized_email' => $key,
            'validation_status' => ValidationStatus::Unknown->value,
            'validation_reason' => ValidationReason::NotValidated->value,
            'validation_method' => ValidationMethod::None->value,
            'is_catch_all' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ], $keys);

        Contact::query()->insertOrIgnore($rows);

        $contactIds = Contact::query()
            ->where('user_id', $list->user_id)
            ->whereIn('normalized_email', $keys)
            ->pluck('id')
            ->all();

        $memberships = array_map(fn ($id): array => [
            'list_id' => $list->id,
            'contact_id' => $id,
            'user_id' => $list->user_id,
            'added_at' => $now,
        ], $contactIds);

        if ($memberships === []) {
            return;
        }

        // `insertOrIgnore` rather than `upsert` here, deliberately: an upsert's
        // update list would fire on every row that already existed, touching
        // `added_at` on a membership the customer added last month and silently
        // moving it to the top of the list.
        DB::table('list_contacts')->insertOrIgnore($memberships);
    }

    /**
     * A list belonging to this account, or 404.
     */
    private function owned(Request $request, mixed $list): ContactList
    {
        if (! is_numeric((string) $list)) {
            abort(404);
        }

        $record = ContactList::query()->find((int) $list);

        if ($record === null || (int) $record->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        return $record;
    }
}

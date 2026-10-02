<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Audience\AudienceEligibility;
use App\Domain\Audience\ConsentLedger;
use App\Domain\Audience\ConsentSource;
use App\Domain\Audience\ConsentStatus;
use App\Domain\Audience\SuppressionList;
use App\Domain\Audience\SuppressionReason;
use App\Domain\Audience\UnsubscribeLink;
use App\Domain\Audience\ValidationStatus;
use App\Http\Controllers\Audience\ListController;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\ContactListMembership;
use App\Models\Suppression;
use App\Models\UnsubscribeToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Contacts, lists, consent, suppression and the unsubscribe link.
 *
 * Three properties are under test, and each one is a way this stage could
 * quietly lose a customer:
 *
 *   - **One person, one contact.** The same address pasted twice, into two lists,
 *     by two different tasks, is still one row per tenant. A copy per list is what
 *     makes "did I unsubscribe this person?" unanswerable without checking every
 *     copy.
 *   - **One tenant's audience is invisible to another's.** Sequential ids make a
 *     cross-tenant read a successful guess rather than an obvious mistake, so it
 *     is tested rather than assumed.
 *   - **Suppression survives everything that is not the queue.** An import, a
 *     re-paste, a rename, a deletion — none of them may make a suppressed address
 *     contactable again.
 */
class AudienceManagementTest extends TestCase
{
    // Canonical contacts

    public function test_the_same_address_is_one_contact_per_tenant(): void
    {
        $user = User::factory()->create();

        Contact::query()->create(['user_id' => $user->id, 'email' => 'a@b.test', 'normalized_email' => 'a@b.test']);

        // The unique constraint is the mechanism; the second insert is not an
        // error the customer should be shown, so it is ignored rather than
        // rejected.
        DB::table('contacts')->insertOrIgnore([
            'user_id' => $user->id,
            'email' => 'a@b.test',
            'normalized_email' => 'a@b.test',
            'validation_status' => ValidationStatus::Unknown->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, Contact::query()->where('user_id', $user->id)->count());
    }

    public function test_two_tenants_may_hold_the_same_address_independently(): void
    {
        // The constraint is per tenant, not global: two accounts can legitimately
        // hold the same address, and forcing them to share one row would leak one
        // tenant's evidence to the other.
        $first = User::factory()->create();
        $second = User::factory()->create();

        Contact::factory()->for($first)->create(['normalized_email' => 'shared@example.com']);
        Contact::factory()->for($second)->create(['normalized_email' => 'shared@example.com']);

        $this->assertSame(1, Contact::query()->where('user_id', $first->id)->count());
        $this->assertSame(1, Contact::query()->where('user_id', $second->id)->count());
        $this->assertSame(2, Contact::query()->count());
    }

    public function test_a_contact_is_not_reachable_through_another_tenants_list(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $list = ContactList::factory()->for($owner)->create();

        $this->actingAs($other)->get("/lists/{$list->id}")->assertNotFound();
    }

    public function test_another_tenants_list_contributes_nothing_to_a_membership_count(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $mine = ContactList::factory()->for($owner)->create();
        $theirs = ContactList::factory()->for($other)->create();

        $contact = Contact::factory()->for($owner)->create();

        $this->addMembership($theirs, $contact->id);

        $this->assertSame(0, $mine->refresh()->memberships()->count());
        $this->assertSame(1, $theirs->refresh()->memberships()->count());
    }

    // Lists

    public function test_adding_the_same_address_twice_does_not_duplicate_membership(): void
    {
        $user = User::factory()->create();
        $list = ContactList::factory()->for($user)->create();

        $this->addMembership($list, $this->contactIdFor($user, 'a@b.test'));
        $this->addMembership($list, $this->contactIdFor($user, 'a@b.test'));

        $this->assertSame(1, $list->refresh()->memberships()->count());
    }

    public function test_removing_a_contact_from_a_list_touches_nothing_else(): void
    {
        // Least of all the suppression. A recipient who unsubscribed must not
        // become contactable because the customer tidied a bucket up.
        $user = User::factory()->create();
        $list = ContactList::factory()->for($user)->create();

        $contact = Contact::factory()->for($user)->create();
        $this->addMembership($list, $contact->id);

        app(SuppressionList::class)->suppress($contact, SuppressionReason::Unsubscribed);

        ContactListMembership::query()->where('list_id', $list->id)->delete();

        $this->assertSame(1, Contact::query()->whereKey($contact->id)->count());
        $this->assertTrue(app(SuppressionList::class)->isSuppressed($contact->refresh()));
    }

    public function test_deleting_a_list_keeps_the_contacts_on_it(): void
    {
        // Deleting a bucket must not delete the person, and a contact whose
        // suppression has to survive their account tidying up must outlive the
        // list they were on.
        $user = User::factory()->create();
        $list = ContactList::factory()->for($user)->create();

        $contact = Contact::factory()->for($user)->confirmedInvalid()->create();
        $this->addMembership($list, $contact->id);

        $list->delete();

        $this->assertSame(1, Contact::query()->whereKey($contact->id)->count());
        $this->assertSame(0, ContactListMembership::query()->where('list_id', $list->id)->count());
    }

    // Consent

    public function test_an_import_never_fabricates_consent(): void
    {
        // A scraped or purchased list has no consent attached to it, and a
        // platform that quietly promoted it to confirmed would be manufacturing
        // permission out of nothing.
        $contact = Contact::factory()->create();

        $ledger = app(ConsentLedger::class);

        $this->assertSame(ConsentStatus::Unknown, $ledger->statusFor([]));

        $ledger->record($contact, ConsentSource::ImportedAttestation);

        $this->assertSame(
            ConsentStatus::Unknown,
            $ledger->statusFor($contact->refresh()->consents->all()),
            'an operator attestation is evidence of nothing to the recipient',
        );
    }

    public function test_a_record_the_recipient_produced_confirms_consent(): void
    {
        $contact = Contact::factory()->create();

        $ledger = app(ConsentLedger::class);

        $ledger->record($contact, ConsentSource::DoubleOptIn, recipientConfirmed: true);

        $this->assertSame(
            ConsentStatus::Confirmed,
            $ledger->statusFor($contact->refresh()->consents->all()),
        );
    }

    public function test_a_withdrawal_outranks_the_confirmation_before_it(): void
    {
        // A recipient who asked to stop must be able to revoke without an
        // operator having to find and edit the record that opted them in.
        $contact = Contact::factory()->create();
        $ledger = app(ConsentLedger::class);

        $ledger->record($contact, ConsentSource::DoubleOptIn, recipientConfirmed: true);
        $ledger->withdraw($contact);

        $this->assertSame(
            ConsentStatus::Withdrawn,
            $ledger->statusFor($contact->refresh()->consents->all()),
        );
    }

    public function test_a_newer_confirmation_overrides_an_older_withdrawal(): void
    {
        // A recipient who opts back in later is entitled to be reachable, and the
        // comparison is on timestamps rather than on insertion order.
        $contact = Contact::factory()->create();
        $ledger = app(ConsentLedger::class);

        $ledger->record(
            $contact,
            ConsentSource::DoubleOptIn,
            recipientConfirmed: true,
            grantedAt: now()->subMonths(2),
        );

        $ledger->withdraw($contact, now()->subMonth());

        $ledger->record($contact, ConsentSource::DoubleOptIn, recipientConfirmed: true, grantedAt: now());

        $this->assertSame(
            ConsentStatus::Confirmed,
            $ledger->statusFor($contact->refresh()->consents->all()),
        );
    }

    public function test_consent_withdrawn_is_a_record_not_a_deletion(): void
    {
        // "Withdrawn" has to stay distinguishable from "never had any".
        $contact = Contact::factory()->create();
        $ledger = app(ConsentLedger::class);

        $ledger->record($contact, ConsentSource::DoubleOptIn, recipientConfirmed: true);
        $ledger->withdraw($contact);

        $this->assertSame(1, $contact->consents()->count());
        $this->assertNotNull($contact->consents()->firstOrFail()->withdrawn_at);
    }

    // Suppression

    public function test_suppressing_twice_records_one_row(): void
    {
        // A recipient clicking the link twice must get the same outcome and must
        // not produce a second row.
        $contact = Contact::factory()->create();
        $suppressions = app(SuppressionList::class);

        $first = $suppressions->suppress($contact, SuppressionReason::Unsubscribed);
        $second = $suppressions->suppress($contact, SuppressionReason::Complaint);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Suppression::query()->where('contact_id', $contact->id)->count());
    }

    public function test_a_complaint_is_not_downgraded_by_a_later_weaker_reason(): void
    {
        // The recorded reason is what a support agent asking "why was this
        // customer complained about" needs to be right about.
        $contact = Contact::factory()->create();
        $suppressions = app(SuppressionList::class);

        $suppressions->suppress($contact, SuppressionReason::Complaint);
        $suppressions->suppress($contact, SuppressionReason::Manual);

        $this->assertSame(
            SuppressionReason::Complaint,
            Suppression::query()->where('contact_id', $contact->id)->firstOrFail()->reason,
        );
    }

    public function test_an_unsubscribe_can_never_be_lifted(): void
    {
        $contact = Contact::factory()->create();
        $suppressions = app(SuppressionList::class);

        $row = $suppressions->suppress($contact, SuppressionReason::Unsubscribed);

        $this->assertFalse(
            $suppressions->clear($row),
            'offering to undo a recipient exercising a right is how a suppression list becomes recurring abuse',
        );
        $this->assertSame(1, Suppression::query()->count());
    }

    public function test_a_complaint_can_never_be_lifted_either(): void
    {
        $contact = Contact::factory()->create();
        $suppressions = app(SuppressionList::class);

        $row = $suppressions->suppress($contact, SuppressionReason::Complaint);

        $this->assertFalse($suppressions->clear($row));
        $this->assertSame(1, Suppression::query()->count());
    }

    public function test_reimporting_a_suppressed_list_cannot_make_it_contactable_again(): void
    {
        // The sequence this whole table exists to make impossible:
        // recipient unsubscribes, tenant imports the same list tomorrow, tenant
        // sends to them again.
        $user = User::factory()->create();
        $list = ContactList::factory()->for($user)->create();

        $contact = Contact::factory()->for($user)->likelyActive()->create();
        $this->addMembership($list, $contact->id);

        $ledger = app(ConsentLedger::class);
        $ledger->record($contact, ConsentSource::DoubleOptIn, recipientConfirmed: true);

        $eligibility = app(AudienceEligibility::class);
        $this->assertSame(1, $eligibility->countFor((int) $user->id));

        app(SuppressionList::class)->suppress($contact, SuppressionReason::Unsubscribed);

        // Re-imported: same address, same list, same contact.
        $this->addMembership($list, $this->contactIdFor($user, $contact->normalized_email));

        $this->assertSame(
            0,
            $eligibility->countFor((int) $user->id),
            'a re-import must not resurrect a suppressed recipient',
        );
        $this->assertSame(1, $eligibility->breakdownFor((int) $user->id)['suppressed']);
    }

    public function test_a_suppression_is_scoped_to_the_tenant_that_recorded_it(): void
    {
        $mine = Contact::factory()->create();
        $theirs = Contact::factory()->create(['normalized_email' => 'same@example.com']);

        app(SuppressionList::class)->suppress($mine, SuppressionReason::Unsubscribed);

        $this->assertTrue(app(SuppressionList::class)->isSuppressed($mine));
        $this->assertFalse(
            app(SuppressionList::class)->isSuppressed($theirs),
            'one account unsubscribing somebody is not an instruction to every account',
        );
    }

    // Unsubscribe link

    public function test_an_unsubscribe_token_is_opaque_and_stored_as_a_digest(): void
    {
        // Nothing in the URL or in a leaked backup identifies a recipient.
        $contact = Contact::factory()->create();

        $token = app(UnsubscribeLink::class)->issue($contact);

        $row = UnsubscribeToken::query()->firstOrFail();

        $this->assertNotSame($token, $row->token_hash);
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertStringNotContainsString($contact->normalized_email, $token);

        // The stored row identifies the contact by id because it has to — but it
        // stores no token that could be reversed, and no address, so a leaked
        // backup cannot be walked back to a recipient's inbox.
        $this->assertStringNotContainsString($contact->normalized_email, json_encode($row->getAttributes()));
        $this->assertSame(
            64,
            strlen($token),
            'an entropy-short token is a token an attacker can guess',
        );
    }

    public function test_each_issued_token_is_distinct(): void
    {
        $contact = Contact::factory()->create();
        $links = app(UnsubscribeLink::class);

        $this->assertNotSame($links->issue($contact), $links->issue($contact));
    }

    public function test_a_guessable_token_resolves_to_nobody(): void
    {
        $contact = Contact::factory()->create();
        $links = app(UnsubscribeLink::class);

        $links->issue($contact);

        $this->assertNull($links->resolve(str_repeat('a', 64)));
        $this->assertNull($links->resolve('short'));
        $this->assertNull($links->resolve(''));
    }

    public function test_unsubscribing_takes_effect_immediately_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->likelyActive()->create();

        $ledger = app(ConsentLedger::class);
        $ledger->record($contact, ConsentSource::DoubleOptIn, recipientConfirmed: true);

        $token = app(UnsubscribeLink::class)->issue($contact);
        $links = app(UnsubscribeLink::class);
        $suppressions = app(SuppressionList::class);

        $this->assertTrue($links->unsubscribe($token, $suppressions, $ledger));

        // Immediate, with no queue in the path: read straight after the click.
        $this->assertTrue($suppressions->isSuppressed($contact->refresh()));
        $this->assertSame(
            ConsentStatus::Withdrawn,
            $ledger->statusFor($contact->refresh()->consents->all()),
        );

        // Idempotent. The second click is not an error the recipient is shown.
        $this->assertFalse($links->unsubscribe($token, $suppressions, $ledger));
        $this->assertSame(1, Suppression::query()->count());
    }

    public function test_unsubscribing_removes_nothing_from_any_list(): void
    {
        // Deleting the membership would make the exclusion invisible on the one
        // page the customer can actually see, which is the last place it should
        // be. The tenant-scoped suppression row is what keeps them off every list
        // and every future campaign.
        $user = User::factory()->create();
        $list = ContactList::factory()->for($user)->create();

        $contact = Contact::factory()->for($user)->likelyActive()->create();
        $this->addMembership($list, $contact->id);

        $links = app(UnsubscribeLink::class);
        $links->unsubscribe(
            $links->issue($contact),
            app(SuppressionList::class),
            app(ConsentLedger::class),
        );

        $this->assertSame(1, $list->refresh()->memberships()->count());
        $this->assertSame(0, app(AudienceEligibility::class)->countFor((int) $user->id));
    }

    public function test_an_unknown_token_suppresses_nobody(): void
    {
        $links = app(UnsubscribeLink::class);

        $this->assertFalse($links->unsubscribe(
            str_repeat('z', 64),
            app(SuppressionList::class),
            app(ConsentLedger::class),
        ));

        $this->assertSame(0, Suppression::query()->count());
    }

    public function test_the_unsubscribe_page_never_needs_an_account(): void
    {
        $contact = Contact::factory()->create();
        $token = app(UnsubscribeLink::class)->issue($contact);

        $this->get("/unsubscribe/{$token}")->assertOk();
    }

    public function test_the_unsubscribe_page_does_not_echo_the_address(): void
    {
        // A confirmation is a disclosure, and this link travels through proxies,
        // logs and browser history on its way to the recipient.
        $contact = Contact::factory()->create(['normalized_email' => 'private.person@example.com']);
        $token = app(UnsubscribeLink::class)->issue($contact);

        $this->get("/unsubscribe/{$token}")
            ->assertOk()
            ->assertDontSee('private.person@example.com');
    }

    public function test_a_get_request_does_not_unsubscribe_anybody(): void
    {
        // Mail clients, chat apps and link scanners fetch URLs automatically.
        // Making the change require a POST is what stops one of them deciding for
        // the recipient.
        $contact = Contact::factory()->create();
        $token = app(UnsubscribeLink::class)->issue($contact);

        $this->get("/unsubscribe/{$token}")->assertOk();

        $this->assertFalse(app(SuppressionList::class)->isSuppressed($contact));
    }

    public function test_posting_the_link_unsubscribes_the_recipient(): void
    {
        $contact = Contact::factory()->create();
        $token = app(UnsubscribeLink::class)->issue($contact);

        $this->post("/unsubscribe/{$token}")->assertOk();

        $this->assertTrue(app(SuppressionList::class)->isSuppressed($contact));
    }

    public function test_an_unresolvable_link_gets_the_same_page_rather_than_an_error(): void
    {
        // A 404 would tell a well-meaning recipient their link is broken, and
        // would tell an attacker probing tokens which of them are real.
        $this->get('/unsubscribe/'.str_repeat('q', 64))->assertOk();
    }

    public function test_a_token_cannot_act_on_another_tenants_contact_by_id(): void
    {
        // There is no path that accepts a contact identifier, so no caller —
        // including the controller — can unsubscribe somebody by guessing an id.
        $contact = Contact::factory()->create();
        $token = app(UnsubscribeLink::class)->issue($contact);

        $this->post("/unsubscribe/{$token}")->assertOk();

        $this->assertSame(1, Suppression::query()->count());
        $this->assertSame((int) $contact->user_id, (int) Suppression::query()->firstOrFail()->user_id);
    }

    // Eligibility

    public function test_only_checked_consented_unsuppressed_contacts_are_eligible(): void
    {
        $user = User::factory()->create();
        $ledger = app(ConsentLedger::class);

        $ready = Contact::factory()->for($user)->likelyActive()->create();
        $ledger->record($ready, ConsentSource::DoubleOptIn, recipientConfirmed: true);

        Contact::factory()->for($user)->likelyActive()->create();            // no consent
        Contact::factory()->for($user)->confirmedInvalid()->create();        // dead
        Contact::factory()->for($user)->create();                            // never checked

        $unknown = Contact::factory()->for($user)->unknown()->create();
        $ledger->record($unknown, ConsentSource::DoubleOptIn, recipientConfirmed: true);

        $eligibility = app(AudienceEligibility::class);

        $this->assertSame(
            1,
            $eligibility->countFor((int) $user->id),
            'unknown is excluded by default: not sending loses one recipient, sending loses the reputation',
        );
    }

    public function test_an_expired_positive_verification_stops_being_eligible(): void
    {
        // A mailbox's answer can change between one message and the next. There
        // is no "confirmed valid forever" state on purpose.
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->likelyActive()->create();

        app(ConsentLedger::class)->record($contact, ConsentSource::DoubleOptIn, recipientConfirmed: true);

        $this->assertSame(1, app(AudienceEligibility::class)->countFor((int) $user->id));

        $contact->forceFill(['validation_expires_at' => now()->subDay()])->save();

        $this->assertSame(0, app(AudienceEligibility::class)->countFor((int) $user->id));
    }

    public function test_the_breakdown_counts_overlap_and_says_so(): void
    {
        // Only `eligible` is exclusive. A suppressed address may also be
        // unvalidated, and both facts are true and both are worth showing —
        // presenting the others as a partition that sums to the list total would
        // be a claim about the data the data does not support.
        $user = User::factory()->create();

        Contact::factory()->for($user)->likelyActive()->create();

        $gone = Contact::factory()->for($user)->create();
        app(SuppressionList::class)->suppress($gone, SuppressionReason::Unsubscribed);

        $breakdown = app(AudienceEligibility::class)->breakdownFor((int) $user->id);

        $this->assertSame(0, $breakdown['eligible']);
        $this->assertSame(1, $breakdown['suppressed']);
        $this->assertSame(1, $breakdown['unchecked']);
        $this->assertGreaterThan(
            $breakdown['eligible'],
            $breakdown['suppressed'] + $breakdown['unchecked'],
            'the non-exclusive counts must not read as a partition',
        );
    }

    /**
     * Attach a membership, going through the constraint rather than a lookup.
     *
     * Mirrors what {@see ListController} does, so
     * the tests exercise the same write the product performs.
     */
    private function addMembership(ContactList $list, int $contactId): void
    {
        DB::table('list_contacts')->insertOrIgnore([
            'list_id' => $list->id,
            'contact_id' => $contactId,
            'user_id' => $list->user_id,
            'added_at' => now(),
        ]);
    }

    /**
     * The canonical contact for an address a paste created, whatever its id.
     */
    private function contactIdFor(User $user, string $normalizedEmail): int
    {
        $contact = Contact::query()->firstOrCreate(
            ['user_id' => $user->id, 'normalized_email' => $normalizedEmail],
            ['email' => $normalizedEmail],
        );

        return (int) $contact->id;
    }
}

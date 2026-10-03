<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Audience\ConsentLedger;
use App\Domain\Audience\ConsentSource;
use App\Domain\Audience\SuppressionList;
use App\Domain\Audience\SuppressionReason;
use App\Domain\Campaigns\Campaign;
use App\Domain\Campaigns\CampaignPreflight;
use App\Domain\Campaigns\MessageTransport;
use App\Domain\Mail\DeliveryReadiness;
use App\Domain\Mail\DomainAuthenticationEvidence;
use App\Domain\Mail\SenderIdentityPolicy;
use App\Domain\Mail\SmtpAccount;
use App\Domain\Templates\Template;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\ContactListMembership;
use App\Models\User;
use Tests\Feature\Fakes\RecordingTransport;

/**
 * Shared setup for the campaign tests.
 *
 * Builds the whole sendable world explicitly rather than through the customer UI:
 * a verified transport, a complete template, and a list whose membership explains
 * itself. Each helper creates exactly one condition, so a test that wants "a
 * contact with no consent" states that and nothing else is implied.
 *
 * Two arrangements are worth knowing about:
 *
 *   - **DNS evidence is stubbed.** A missing DMARC record legitimately blocks bulk
 *     sending, and `example.com` genuinely has none, so without a stub no campaign
 *     could ever be launched by a test — and worse, a passing test would be
 *     asserting nothing about sending. The stub declares the domain authenticated,
 *     which is a statement about the test fixture's domain, not about the code.
 *   - **The transport is recorded, never contacted.** No test opens a socket.
 */
abstract class CampaignTestCase extends MailTestCase
{
    protected RecordingTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = new RecordingTransport;

        $this->app->instance(MessageTransport::class, $this->transport);

        // A sending domain that publishes what bulk senders are required to
        // publish, so the readiness check is exercised rather than stubbed out.
        $this->app->instance(DeliveryReadiness::class, new DeliveryReadiness(
            app(SenderIdentityPolicy::class),
            new class extends DomainAuthenticationEvidence
            {
                public function spfRecords(string $domain): array
                {
                    return ['v=spf1 include:_spf.example.test ~all'];
                }

                public function hasSpf(string $domain): bool
                {
                    return $this->spfRecords($domain) !== [];
                }

                public function dkimRecords(string $domain, string $selector): array
                {
                    return ['v=DKIM1; k=rsa; p='];
                }

                public function hasDkimSelector(string $domain, string $selector): bool
                {
                    return $this->dkimRecords($domain, $selector) !== [];
                }

                public function hasDmarc(string $domain): bool
                {
                    return true;
                }

                public function dmarcPolicy(string $domain): ?string
                {
                    return 'reject';
                }

                public function dmarcAlignment(string $domain): array
                {
                    return ['adkim' => 'r', 'aspf' => 'r'];
                }
            },
        ));
    }

    /**
     * A confirmed account, since every campaign test needs someone to be.
     */
    protected function signedInTenant(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user->fresh();
    }

    /**
     * A transport that has been verified, which is the state a campaign needs.
     */
    protected function verifiedAccountFor(User $user, array $overrides = []): SmtpAccount
    {
        $account = $this->accountFor($user, $overrides);

        $account->markVerified(3600);

        return $account->fresh();
    }

    protected function readyTemplateFor(User $user, array $overrides = []): Template
    {
        return Template::factory()->create(array_merge(['user_id' => $user->id], $overrides));
    }

    protected function listFor(User $user): ContactList
    {
        return ContactList::factory()->create(['user_id' => $user->id]);
    }

    /**
     * Someone this platform may contact: validated, consenting, not suppressed.
     */
    protected function eligibleContact(User $user, ContactList $list): Contact
    {
        $contact = Contact::factory()->likelyActive()->create(['user_id' => $user->id]);

        app(ConsentLedger::class)->record($contact, ConsentSource::DoubleOptIn, recipientConfirmed: true);

        $this->addToList($contact, $list);

        return $contact;
    }

    /**
     * Somebody nobody may contact, for one reason.
     */
    protected function contactWithoutConsent(User $user, ContactList $list): Contact
    {
        $contact = Contact::factory()->likelyActive()->create(['user_id' => $user->id]);

        $this->addToList($contact, $list);

        return $contact;
    }

    protected function suppressedContact(User $user, ContactList $list): Contact
    {
        $contact = $this->eligibleContact($user, $list);

        app(SuppressionList::class)->suppress($contact, SuppressionReason::Unsubscribed);

        return $contact;
    }

    protected function unusableContact(User $user, ContactList $list): Contact
    {
        $contact = Contact::factory()->confirmedInvalid()->create(['user_id' => $user->id]);

        app(ConsentLedger::class)->record($contact, ConsentSource::DoubleOptIn, recipientConfirmed: true);

        $this->addToList($contact, $list);

        return $contact;
    }

    /**
     * A draft campaign wired to a transport, a template and a list.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function draftFor(User $user, array $overrides = []): Campaign
    {
        $account = $this->verifiedAccountFor($user);
        $template = $this->readyTemplateFor($user);
        $list = $this->listFor($user);

        return Campaign::factory()->create(array_merge([
            'user_id' => $user->id,
            'template_id' => $template->id,
            'smtp_account_id' => $account->id,
            'list_id' => $list->id,
        ], $overrides));
    }

    /**
     * The preflight, resolved the same way the controller resolves it.
     */
    protected function preflight(): CampaignPreflight
    {
        return app(CampaignPreflight::class);
    }

    protected function addToList(Contact $contact, ContactList $list): void
    {
        ContactListMembership::query()->create([
            'user_id' => $contact->user_id,
            'list_id' => $list->id,
            'contact_id' => $contact->id,
        ]);
    }
}

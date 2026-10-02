<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Mail\SmtpAccount;
use App\Domain\Mail\SmtpAuthMode;
use App\Domain\Mail\SmtpEncryption;
use App\Domain\Mail\SmtpManagementMode;
use App\Domain\Mail\SmtpProvider;
use App\Models\User;

/**
 * A tenant's transports are theirs, and the distinction is a 404.
 *
 * The reason to assert on the *code* is that it is the difference between "you
 * may not see this" and "this does not exist". A 403 confirms the row is there,
 * which is itself information a non-owner should not be given — and it turns
 * every id in the sequence into a way to discover how many transports the
 * platform holds.
 */
class SmtpAccountOwnershipTest extends MailTestCase
{
    public function test_the_owner_can_reach_every_page_of_their_transport(): void
    {
        $user = User::factory()->create();
        $account = $this->accountFor($user);

        foreach ([
            route('account.smtp.index'),
            route('account.smtp.show', $account),
            route('account.smtp.edit', $account),
        ] as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }
    }

    public function test_another_user_receives_not_found_rather_than_forbidden(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $account = $this->accountFor($owner);

        foreach ([
            route('account.smtp.show', $account),
            route('account.smtp.edit', $account),
        ] as $url) {
            $this->actingAs($other)->get($url)->assertNotFound();
        }

        $this->actingAs($other)
            ->put(route('account.smtp.update', $account), $this->payload())
            ->assertNotFound();

        $this->actingAs($other)
            ->delete(route('account.smtp.destroy', $account))
            ->assertNotFound();

        // Untouched: a refused request must not have deleted anything.
        $this->assertDatabaseHas('smtp_accounts', ['id' => $account->getKey()]);
    }

    public function test_another_users_transport_never_appears_in_a_list(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $this->accountFor($owner, ['label' => 'Owners transport']);
        $this->accountFor($other, ['label' => 'Somebody elses']);

        $response = $this->actingAs($other)->get(route('account.smtp.index'));

        $response->assertOk();
        $this->assertStringNotContainsString('Owners transport', $response->getContent());
        $this->assertStringContainsString('Somebody elses', $response->getContent());
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $account = $this->accountFor(User::factory()->create());

        $this->get(route('account.smtp.index'))->assertRedirect(route('login'));
        $this->get(route('account.smtp.show', $account))->assertRedirect(route('login'));
    }

    public function test_an_unconfirmed_account_cannot_reach_the_pages(): void
    {
        $user = User::factory()->unverified()->create();
        $account = $this->accountFor($user);

        $this->actingAs($user)->get(route('account.smtp.index'))->assertRedirect(route('verification.notice'));
        $this->actingAs($user)->get(route('account.smtp.show', $account))->assertRedirect(route('verification.notice'));
    }

    public function test_a_user_cannot_edit_an_administrator_managed_transport(): void
    {
        $user = User::factory()->create();
        $account = $this->accountFor($user, [
            'management_mode' => SmtpManagementMode::AdminManaged->value,
        ]);

        // Not found rather than forbidden: the row exists, but this user has no
        // claim on it and should not learn that it exists at that path.
        $this->actingAs($user)->get(route('account.smtp.edit', $account))->assertNotFound();
        $this->actingAs($user)
            ->put(route('account.smtp.update', $account), $this->payload())
            ->assertNotFound();
        $this->actingAs($user)
            ->delete(route('account.smtp.destroy', $account))
            ->assertNotFound();

        $this->actingAs($user)->get(route('account.smtp.show', $account))->assertOk();
    }

    public function test_an_owner_may_delete_their_own_transport(): void
    {
        $user = User::factory()->create();
        $account = $this->accountFor($user, [
            'management_mode' => SmtpManagementMode::UserManaged->value,
        ]);

        $this->actingAs($user)
            ->delete(route('account.smtp.destroy', $account))
            ->assertRedirect(route('account.smtp.index'));

        $this->assertDatabaseMissing('smtp_accounts', ['id' => $account->getKey()]);
    }

    public function test_an_owner_may_hold_several_transports(): void
    {
        $user = User::factory()->create();

        $this->accountFor($user, ['label' => 'Work', 'host' => 'smtp.work.example.com']);
        $this->accountFor($user, ['label' => 'Personal', 'host' => 'smtp.personal.example.com']);

        $response = $this->actingAs($user)->get(route('account.smtp.index'));

        $response->assertOk();
        $this->assertSame(
            SmtpAccount::query()->ownedBy($user->getKey())->count(),
            2,
            'Multiple transports are allowed; nothing tries them in turn.',
        );
    }

    public function test_a_stored_transport_is_never_returned_as_ready_by_construction(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('account.smtp.store'), $this->payload())
            ->assertRedirect();

        $stored = SmtpAccount::query()->firstOrFail();

        $this->assertFalse($stored->effectiveStatus()->isUsable());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'label' => 'Primary',
            'provider' => SmtpProvider::Custom->value,
            'host' => 'mail.example.com',
            'port' => 465,
            'encryption' => SmtpEncryption::ImplicitTls->value,
            'auth_mode' => SmtpAuthMode::Password->value,
            'username' => 'alice@example.com',
            'secret' => 'a-password',
            'from_address' => 'alice@example.com',
            'from_name' => 'Alice',
            'reply_to' => 'support@example.com',
            'dkim_selector' => 'selector1',
        ];
    }
}

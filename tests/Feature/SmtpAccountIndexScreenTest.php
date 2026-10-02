<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Mail\SmtpAccountStatus;
use App\Domain\Mail\SmtpEncryption;
use App\Domain\Mail\SmtpManagementMode;
use App\Domain\Mail\SmtpProvider;
use App\Models\User;

/**
 * The customer's mail accounts page.
 *
 * This is the screen a non-technical customer sees first when they ask what the
 * product wants from them, so it is written in their words: no transports, no
 * endpoints, no encryption modes, and no claim that anything is being sent on
 * their behalf while the sending features are still being built. What it must
 * still be exact about is ownership — one account holder never sees another's
 * account, in the list or in the links.
 */
class SmtpAccountIndexScreenTest extends MailTestCase
{
    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('account.smtp.index'))->assertRedirect(route('login'));
    }

    public function test_an_unconfirmed_account_is_asked_to_confirm_first(): void
    {
        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('account.smtp.index'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_the_page_renders_for_a_confirmed_account(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('account.smtp.index'))
            ->assertOk();
    }

    public function test_the_page_is_titled_in_customer_language(): void
    {
        $page = $this->main();

        $this->assertSame(1, substr_count($this->page(), '<h1'));
        $this->assertStringContainsString('Mail accounts', $page);
        $this->assertStringContainsString('Connect and verify the mail account you plan to use', $page);

        // The vocabulary this page used to lead with.
        $this->assertStringNotContainsString('Mail transports', $page);
        $this->assertStringNotContainsString('Add a transport', $page);
    }

    public function test_the_add_action_is_obvious_and_points_at_the_real_form(): void
    {
        $content = $this->page();

        $this->assertStringContainsString('Add mail account', $content);
        $this->assertStringContainsString('href="'.route('account.smtp.create').'"', $content);
        $this->assertGreaterThanOrEqual(1, substr_count($content, 'href="'.route('account.smtp.create').'"'));
    }

    public function test_each_account_is_described_in_words_a_customer_uses(): void
    {
        $user = User::factory()->create();

        $this->accountFor($user, [
            'label' => 'Work mailbox',
            'provider' => SmtpProvider::Gmail,
            'from_address' => 'ada@work.example.com',
            'username' => 'ada@work.example.com',
        ]);

        $content = (string) $this->actingAs($user)->get(route('account.smtp.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Work mailbox', $content);
        $this->assertStringContainsString('Gmail', $content);
        $this->assertStringContainsString('Sends as', $content);
        $this->assertStringContainsString('ada@work.example.com', $content);
        $this->assertStringContainsString(SmtpAccountStatus::Unverified->label(), $content);
        $this->assertStringContainsString('Managed by you', $content);
    }

    /**
     * Several accounts are ordinary, and each keeps its own identity.
     */
    public function test_several_accounts_are_all_listed(): void
    {
        $user = User::factory()->create();

        $this->accountFor($user, ['label' => 'Work', 'from_address' => 'ada@work.example.com']);
        $this->accountFor($user, ['label' => 'Personal', 'from_address' => 'ada@home.example.com']);

        $content = (string) $this->actingAs($user)->get(route('account.smtp.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Work', $content);
        $this->assertStringContainsString('Personal', $content);
        $this->assertStringContainsString('ada@work.example.com', $content);
        $this->assertStringContainsString('ada@home.example.com', $content);
    }

    public function test_every_state_is_shown_in_the_applications_own_words(): void
    {
        $user = User::factory()->create();

        $unverified = $this->accountFor($user, ['label' => 'Never checked']);
        $failed = $this->accountFor($user, ['label' => 'Refused']);
        $disabled = $this->accountFor($user, ['label' => 'Switched off']);

        $failed->forceFill(['status' => SmtpAccountStatus::Failed->value])->save();
        $disabled->forceFill(['status' => SmtpAccountStatus::Disabled->value])->save();

        $ready = $this->accountFor($user, ['label' => 'Working']);
        $ready->markVerified(3600);

        $stale = $this->accountFor($user, ['label' => 'Aged out']);
        $stale->markVerified(3600);
        $stale->forceFill(['verification_expires_at' => now()->subDay()])->save();

        $content = (string) $this->actingAs($user)->get(route('account.smtp.index'))->assertOk()->getContent();

        foreach (SmtpAccountStatus::cases() as $status) {
            $this->assertStringContainsString(
                $status->label(),
                $content,
                "[{$status->name}] must be reported in the application's own wording",
            );
        }

        // A stale verification is a different fact from a failed one, and the page
        // must not flatten both into a single word.
        $this->assertStringContainsString(SmtpAccountStatus::Stale->label(), $content);
        $this->assertStringContainsString(SmtpAccountStatus::Failed->label(), $content);
    }

    public function test_a_platform_managed_account_is_named_and_not_offered_for_editing(): void
    {
        $user = User::factory()->create();

        $yours = $this->accountFor($user, [
            'label' => 'Yours',
            'management_mode' => SmtpManagementMode::UserManaged,
        ]);

        $theirs = $this->accountFor($user, [
            'label' => 'Ours',
            'management_mode' => SmtpManagementMode::AdminManaged,
        ]);

        $content = (string) $this->actingAs($user)->get(route('account.smtp.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Managed by platform', $content);
        $this->assertStringContainsString('Managed by you', $content);

        // Edit is offered only where editing is actually possible.
        $this->assertStringContainsString('href="'.route('account.smtp.edit', $yours).'"', $content);
        $this->assertStringNotContainsString('href="'.route('account.smtp.edit', $theirs).'"', $content);

        // Both remain readable: an account you send through is not a secret.
        $this->assertStringContainsString('href="'.route('account.smtp.show', $theirs).'"', $content);

        $this->assertStringNotContainsString('admin_managed', $content);
        $this->assertStringNotContainsString('user_managed', $content);
    }

    /**
     * One holder never sees another's account, in the list or behind a link.
     */
    public function test_accounts_are_isolated_to_the_signed_in_holder(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->accountFor($alice, ['label' => 'Alice account', 'from_address' => 'alice@work.example.com']);
        $this->accountFor($bob, ['label' => 'Bob account', 'from_address' => 'bob@work.example.com']);

        $asAlice = (string) $this->actingAs($alice)->get(route('account.smtp.index'))->assertOk()->getContent();
        $asBob = (string) $this->actingAs($bob)->get(route('account.smtp.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Alice account', $asAlice);
        $this->assertStringNotContainsString('Bob account', $asAlice);
        $this->assertStringNotContainsString('bob@work.example.com', $asAlice);

        $this->assertStringContainsString('Bob account', $asBob);
        $this->assertStringNotContainsString('Alice account', $asBob);
        $this->assertStringNotContainsString('alice@work.example.com', $asBob);
    }

    public function test_an_account_with_no_accounts_explains_what_to_do(): void
    {
        $content = (string) $this->actingAs(User::factory()->create())
            ->get(route('account.smtp.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No mail accounts yet', $content);
        $this->assertStringContainsString('Add a mail account to connect the service you plan to use', $content);
        $this->assertStringContainsString('href="'.route('account.smtp.create').'"', $content);

        // Nothing invented to fill the space.
        $this->assertStringNotContainsString('Example', $content);
        $this->assertStringNotContainsString('sample', $content);
        $this->assertStringNotContainsString('Sends as', $content);
    }

    public function test_the_navigation_targets_are_real(): void
    {
        $user = User::factory()->create();
        $account = $this->accountFor($user);

        $content = (string) $this->actingAs($user)->get(route('account.smtp.index'))->assertOk()->getContent();

        $this->assertStringContainsString('href="'.route('account.smtp.show', $account).'"', $content);
        $this->assertStringContainsString('View account', $content);
        $this->assertStringContainsString('href="'.route('account.deliverability.index').'"', $content);
        $this->assertStringContainsString('Sending health', $content);
    }

    /**
     * The overview answers "which account is this, who manages it, can I use it".
     * The connection settings belong to the account pages, and the credential
     * belongs nowhere at all.
     */
    public function test_it_exposes_no_connection_detail_and_no_credential(): void
    {
        $user = User::factory()->create();

        $this->accountFor($user, [
            'label' => 'Work mailbox',
            'host' => 'smtp.secret-relay.example.com',
            'port' => 465,
            'encryption' => SmtpEncryption::StartTls,
            'username' => 'login-name@example.com',
            'secret' => 'a-real-looking-secret',
            'from_address' => 'ada@work.example.com',
        ]);

        $content = (string) $this->actingAs($user)->get(route('account.smtp.index'))->assertOk()->getContent();

        // The stored values, not the words that describe them.
        foreach ([
            'smtp.secret-relay.example.com', '465', 'starttls', 'login-name@example.com',
            'a-real-looking-secret', 'Endpoint', 'Encryption', 'unverified', 'user_managed',
        ] as $detail) {
            $this->assertStringNotContainsString($detail, $content);
        }
    }

    /**
     * Nothing on this page may be true only of the machinery behind it.
     */
    public function test_it_reveals_no_internal_detail(): void
    {
        $user = User::factory()->create();
        $this->accountFor($user);

        $content = (string) $this->actingAs($user)->get(route('account.smtp.index'))->assertOk()->getContent();

        foreach ([
            'SmtpAccountController', 'SmtpAccount', 'SmtpAccountStatus', 'Illuminate\\', 'Laravel',
            'Account\\Smtp', 'config(', 'Password::', '@end', '@if', '@foreach', '{{ $', '{!!', '<x-',
            'app.smtp', 'smtp_accounts',
        ] as $internal) {
            $this->assertStringNotContainsString($internal, $content);
        }
    }

    /**
     * Sending is not a live customer workflow yet, so the page must not describe
     * it as one.
     */
    public function test_it_does_not_present_campaign_sending_as_live(): void
    {
        $user = User::factory()->create();
        $this->accountFor($user);

        $content = (string) $this->actingAs($user)->get(route('account.smtp.index'))->assertOk()->getContent();

        foreach ([
            'one chosen per campaign',
            'per campaign',
            'send your campaign',
            'launch a campaign',
            'start sending campaigns',
            'chosen per campaign',
        ] as $claim) {
            $this->assertStringNotContainsString($claim, $content);
        }

        $this->assertStringContainsString('Campaign sending is still being built', $content);

        // And it does not promise anything about where a message lands.
        $this->assertStringNotContainsString('in the inbox', $content);
        $this->assertStringNotContainsString('deliverability score', $content);
    }

    public function test_a_status_message_is_reported_once_by_the_shell(): void
    {
        $content = (string) $this->actingAs(User::factory()->create())
            ->withSession(['status' => 'Your profile has been updated.'])
            ->get(route('account.smtp.index'))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($content, 'Your profile has been updated.'));
    }

    /**
     * Layout facts a screenshot would otherwise be the only proof of.
     */
    public function test_the_layout_is_responsive_rather_than_fixed_width(): void
    {
        $user = User::factory()->create();
        $this->accountFor($user, ['from_address' => 'a-very-long-address-for-wrapping@some-long-domain.example.com']);

        $content = (string) $this->actingAs($user)->get(route('account.smtp.index'))->assertOk()->getContent();

        $this->assertStringContainsString('break-all', $content, 'long addresses must wrap');
        $this->assertStringContainsString('sm:flex-row', $content, 'the card must stack on a phone');
        $this->assertStringContainsString('min-h-11', $content, 'targets must be comfortable to press');

        // No hard-coded pixel width for the page.
        $this->assertDoesNotMatchRegularExpression('/class="[^"]*\bw-\[\d+px\]/', $content);
        $this->assertStringNotContainsString('max-w-full mx-auto w-[', $content);
    }

    private function page(): string
    {
        return (string) $this->actingAs(User::factory()->create())
            ->get(route('account.smtp.index'))
            ->assertOk()
            ->getContent();
    }

    /**
     * The page's own content, without the shell around it.
     *
     * The navigation is drawn by the shared layout and is asserted by the shell's
     * own tests; a page is about what it says, not about what the frame says.
     */
    private function main(): string
    {
        preg_match('/<main\b[^>]*>(.*?)<\/main>/s', $this->page(), $matches);

        return $matches[1] ?? '';
    }
}

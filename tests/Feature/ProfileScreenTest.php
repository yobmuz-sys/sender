<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The profile page.
 *
 * Two things are being protected here. First, that the page stays a form about
 * the account that is signed in: no other account's details, no identifier to
 * choose between, no field that could change what the account is allowed to do.
 * Second, that saying something untrue about the account is avoided — the email
 * address really does change what the account can use, and the page has to say so
 * before the change is made rather than after it is discovered.
 */
class ProfileScreenTest extends TestCase
{
    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get('/account/profile')->assertRedirect('/login');
    }

    public function test_the_page_renders_for_a_signed_in_account(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/account/profile')
            ->assertOk();
    }

    public function test_the_page_has_one_heading_and_two_editable_fields(): void
    {
        $content = (string) $this->actingAs(User::factory()->create())
            ->get('/account/profile')
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($content, '<h1'));
        $this->assertStringContainsString('Manage the name and email address associated with your account.', $content);

        $this->assertMatchesRegularExpression(
            '/<label[^>]*for="name"[^>]*>\s*Name\s*<\/label>/',
            $content,
        );
        $this->assertStringContainsString('type="text"', $content);
        $this->assertStringContainsString('autocomplete="name"', $content);
        $this->assertStringContainsString('id="email"', $content);
        $this->assertStringContainsString('type="email"', $content);
        $this->assertStringContainsString('inputmode="email"', $content);
        $this->assertStringContainsString('spellcheck="false"', $content);
    }

    public function test_the_form_posts_to_the_update_route_with_a_token(): void
    {
        $content = (string) $this->actingAs(User::factory()->create())
            ->get('/account/profile')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'action="'.route('account.profile.update').'"',
            $content,
        );
        $this->assertStringContainsString('name="_token"', $content);
        $this->assertStringContainsString('name="_method" value="PUT"', $content);
        $this->assertStringContainsString('Save changes', $content);
    }

    public function test_the_page_offers_a_way_back_to_the_dashboard(): void
    {
        $content = (string) $this->actingAs(User::factory()->create())
            ->get('/account/profile')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Back to dashboard', $content);
        $this->assertMatchesRegularExpression(
            '/<a[^>]*href="'.preg_quote(route('dashboard'), '/').'"[^>]*>\s*Back to dashboard/',
            $content,
        );
    }

    /**
     * The page is about the account that is signed in, and nobody else's.
     */
    public function test_it_shows_the_signed_in_account_and_no_other(): void
    {
        $someoneElse = User::factory()->create([
            'name' => 'Someone Else',
            'email' => 'someone.else@example.com',
        ]);

        $account = User::factory()->create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
        ]);

        $content = (string) $this->actingAs($account)
            ->get('/account/profile')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Ada Lovelace', $content);
        $this->assertStringContainsString('ada@example.com', $content);
        $this->assertStringNotContainsString('Someone Else', $content);
        $this->assertStringNotContainsString('someone.else@example.com', $content);
    }

    public function test_a_confirmed_account_is_told_it_is_confirmed(): void
    {
        $content = (string) $this->actingAs(User::factory()->create())
            ->get('/account/profile')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Confirmed', $content);
        $this->assertStringNotContainsString('Send a new confirmation link', $content);
    }

    public function test_an_unconfirmed_account_is_told_and_can_request_another_link(): void
    {
        $content = (string) $this->actingAs(User::factory()->unverified()->create())
            ->get('/account/profile')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Not confirmed', $content);
        $this->assertStringContainsString('Send a new confirmation link', $content);
        $this->assertStringContainsString('action="'.route('verification.send').'"', $content);
        $this->assertStringContainsString('name="_token"', $content);
    }

    /**
     * The consequence of changing an address is the single most surprising thing
     * about this form, so it is stated next to the field rather than discovered
     * after saving.
     */
    public function test_the_consequence_of_changing_the_address_is_stated(): void
    {
        $content = (string) $this->actingAs(User::factory()->create())
            ->get('/account/profile')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'Changing this address will require you to confirm the new address',
            $content,
        );
        $this->assertStringContainsString('aria-describedby="email-help"', $content);

        // Which is exactly what the account does: an address it has not confirmed
        // is not an address it may rely on.
        $this->assertStringNotContainsString('Your account will be disabled', $content);
    }

    public function test_a_rejected_name_is_explained_next_to_the_field(): void
    {
        $content = (string) $this->actingAs(User::factory()->create())
            ->from('/account/profile')
            ->followingRedirects()
            ->put('/account/profile', ['name' => '', 'email' => 'ada@example.com'])
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<input[^>]*id="name"[^>]*aria-invalid="true"/', $content);
        $this->assertStringContainsString('aria-describedby="name-error"', $content);
        $this->assertStringContainsString('id="name-error"', $content);
    }

    public function test_a_rejected_address_is_explained_next_to_the_field(): void
    {
        $content = (string) $this->actingAs(User::factory()->create(['email' => 'ada@example.com']))
            ->from('/account/profile')
            ->followingRedirects()
            ->put('/account/profile', ['name' => 'Ada Lovelace', 'email' => 'not-an-address'])
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<input[^>]*id="email"[^>]*aria-invalid="true"/', $content);
        $this->assertStringContainsString('aria-describedby="email-help email-error"', $content);
        $this->assertStringContainsString('id="email-error"', $content);

        // The message a person reads, not the rule that produced it. `required`
        // is checked elsewhere: here it is a legitimate HTML attribute, not text.
        $this->assertStringNotContainsString('lowercase', $content);
        $this->assertStringNotContainsString('max:255', $content);
        $this->assertStringNotContainsString('string,email', $content);
        $this->assertStringContainsString('must be a valid email address', $content);
    }

    public function test_submitted_values_survive_a_rejected_form(): void
    {
        $content = (string) $this->actingAs(User::factory()->create())
            ->from('/account/profile')
            ->followingRedirects()
            ->put('/account/profile', ['name' => 'Ada', 'email' => 'not-an-address'])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="Ada"', $content);
        $this->assertStringContainsString('value="not-an-address"', $content);
    }

    public function test_an_address_already_in_use_is_refused(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs(User::factory()->create())
            ->from('/account/profile')
            ->put('/account/profile', ['name' => 'Ada', 'email' => 'taken@example.com'])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('users', ['email' => 'taken@example.com', 'id' => 2]);
    }

    /**
     * The whole point of changing an address: the old confirmation no longer
     * counts, and a new link is sent to the new address.
     */
    public function test_changing_the_address_requires_confirming_the_new_one(): void
    {
        Notification::fake();

        $account = User::factory()->create([
            'name' => 'Ada',
            'email' => 'ada@example.com',
        ]);

        $this->actingAs($account)
            ->from('/account/profile')
            ->put('/account/profile', ['name' => 'Ada', 'email' => 'new@example.com'])
            ->assertRedirect('/account/profile')
            ->assertSessionHas('status', 'Your profile has been updated.');

        $account->refresh();

        $this->assertSame('new@example.com', $account->email);
        $this->assertNull($account->email_verified_at);
        $this->assertFalse($account->hasVerifiedEmail());
        $this->assertTrue($account->fresh()->hasVerifiedEmail() === false);

        Notification::assertSentTo($account, VerifyEmail::class);

        // Still signed in: changing an address is not signing out.
        $this->assertAuthenticatedAs($account);

        // And the page now tells the truth about the new address.
        $content = (string) $this->actingAs($account)->get('/account/profile')->assertOk()->getContent();
        $this->assertStringContainsString('new@example.com', $content);
        $this->assertStringContainsString('Not confirmed', $content);
        $this->assertStringNotContainsString('ada@example.com', $content);
    }

    public function test_the_confirmation_message_is_shown_once_and_not_repeated_in_the_page(): void
    {
        $account = User::factory()->create([
            'name' => 'Ada Lovelace',
            'email' => 'grace.hopper@example.com',
        ]);

        // The message belongs to the response to the save, not to the page.
        $this->actingAs($account)
            ->from('/account/profile')
            ->put('/account/profile', ['name' => 'Ada Lovelace', 'email' => $account->email])
            ->assertRedirect('/account/profile')
            ->assertSessionHas('status', 'Your profile has been updated.');

        $followed = (string) $this->actingAs($account)
            ->withSession(['status' => 'Your profile has been updated.'])
            ->get('/account/profile')
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($followed, 'Your profile has been updated.'));
    }

    /**
     * A customer-facing page that names its own machinery is a page that has told
     * the reader something they were not meant to learn.
     */
    public function test_it_reveals_no_internal_detail(): void
    {
        $content = (string) $this->actingAs(User::factory()->role(Role::Support)->create())
            ->get('/account/profile')
            ->assertOk()
            ->getContent();

        foreach ([
            'AccountController', 'UpdateProfileRequest', 'Illuminate\\', 'Laravel', 'Permission::',
            'super_admin', 'email_verified_at', '@end', '@if', '@csrf', '{{ $', '{!!',
            'password_hash', 'remember_token',
        ] as $internal) {
            $this->assertStringNotContainsString($internal, $content);
        }

        // The role's own label, not its stored value.
        $this->assertStringContainsString('Support', $content);
        $this->assertStringNotContainsString('support"', $content);
    }

    /**
     * Nothing on this page may change what an account is allowed to do.
     */
    public function test_it_offers_no_account_attribute_a_customer_could_change(): void
    {
        $content = (string) $this->actingAs(User::factory()->create())
            ->get('/account/profile')
            ->assertOk()
            ->getContent();

        foreach (['role', 'permissions', 'status', 'plan', 'suspended', 'timezone', 'avatar'] as $field) {
            $this->assertStringNotContainsString('name="'.$field.'"', $content);
        }
    }
}

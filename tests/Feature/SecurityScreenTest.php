<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The security page.
 *
 * Three things are being protected here. The page must be honest: it used to claim
 * a password change ended every other session, which the application does not do,
 * and a security page that overstates what it protects is worse than one that says
 * nothing. It must not reflect a password back into the response, in any state.
 * And the three labels must actually render — they were written in a form the label
 * component does not support, which left the fields unlabelled.
 */
class SecurityScreenTest extends TestCase
{
    private const CURRENT = 'the-current-password';

    private const NEW = 'a-replacement-password';

    private const CONFIRMATION = 'a-replacement-password';

    private const WRONG = 'not-the-current-password';

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get('/account/security')->assertRedirect('/login');

        $this->put('/account/security', [
            'current_password' => self::CURRENT,
            'password' => self::NEW,
            'password_confirmation' => self::NEW,
        ])->assertRedirect('/login');
    }

    public function test_the_page_renders_for_a_signed_in_account(): void
    {
        $this->actingAs(User::factory()->create(['password' => self::CURRENT]))
            ->get('/account/security')
            ->assertOk();
    }

    /**
     * The label component renders its slot, so a `value="..."` label renders an
     * empty element. This asserts the text a person actually sees, and asserts the
     * broken form is gone.
     */
    public function test_every_field_carries_a_visible_label(): void
    {
        $content = $this->page();

        foreach ([
            'current_password' => 'Current password',
            'password' => 'New password',
            'password_confirmation' => 'Confirm new password',
        ] as $field => $text) {
            $this->assertMatchesRegularExpression(
                '/<label[^>]*for="'.$field.'"[^>]*>\s*'.preg_quote($text, '/').'\s*<\/label>/',
                $content,
                "[{$field}] must render its visible label",
            );
        }

        // The form of the label that renders nothing.
        foreach (['Current password', 'New password', 'Confirm new password'] as $text) {
            $this->assertStringNotContainsString('value="'.$text.'"', $content);
        }
    }

    public function test_the_form_posts_to_the_update_route_with_a_token(): void
    {
        $content = $this->page();

        $this->assertStringContainsString('action="'.route('account.password.update').'"', $content);
        $this->assertStringContainsString('name="_token"', $content);
        $this->assertStringContainsString('name="_method" value="PUT"', $content);
        $this->assertStringContainsString('name="current_password"', $content);
        $this->assertStringContainsString('name="password"', $content);
        $this->assertStringContainsString('name="password_confirmation"', $content);
        $this->assertStringContainsString('autocomplete="current-password"', $content);
        $this->assertStringContainsString('autocomplete="new-password"', $content);
        $this->assertSame(1, substr_count($content, '<h1'));
    }

    public function test_there_is_a_way_back_to_profile_and_to_the_dashboard(): void
    {
        $content = $this->page();

        $this->assertMatchesRegularExpression(
            '/<a[^>]*href="'.preg_quote(route('account.profile'), '/').'"[^>]*>\s*Back to profile/',
            $content,
        );
        $this->assertMatchesRegularExpression(
            '/<a[^>]*href="'.preg_quote(route('dashboard'), '/').'"[^>]*>\s*Back to dashboard/',
            $content,
        );
    }

    /**
     * The reveal control is shared with the authentication screens, so it must
     * still be the same convention here: real buttons, no framework, one script.
     */
    public function test_each_password_field_can_be_revealed_without_a_framework(): void
    {
        $content = $this->page();

        foreach ([
            'current_password' => ['Show current password', 'Hide current password'],
            'password' => ['Show password', 'Hide password'],
            'password_confirmation' => ['Show password confirmation', 'Hide password confirmation'],
        ] as $field => [$show, $hide]) {
            $this->assertStringContainsString('data-password-toggle aria-controls="'.$field.'"', $content);
            $this->assertStringContainsString('data-show-label="'.$show.'"', $content);
            $this->assertStringContainsString('data-hide-label="'.$hide.'"', $content);
            $this->assertStringContainsString('aria-pressed="false"', $content);
            $this->assertStringContainsString('>'.$show.'</span>', $content);
        }

        $this->assertSame(3, substr_count($content, 'data-password-toggle aria-controls'));
        $this->assertStringContainsString('type="button"', $content);

        // One implementation, not one per page: the script is collected once.
        $this->assertSame(1, substr_count($content, "querySelectorAll('[data-password-toggle]')"));
        $this->assertStringNotContainsString('x-data', $content);
        $this->assertStringNotContainsString('Alpine', $content);
        $this->assertStringNotContainsString('Vue', $content);
    }

    public function test_a_wrong_current_password_is_explained_against_its_own_field(): void
    {
        $content = $this->rejected([
            'current_password' => self::WRONG,
            'password' => self::NEW,
            'password_confirmation' => self::NEW,
        ], 'current_password');

        $this->assertSame(1, substr_count($content, 'id="current_password-error"'));
        $this->assertStringContainsString('aria-describedby="current_password-help current_password-error"', $content);
        $this->assertStringContainsString(__('validation.current_password', ['attribute' => 'current password']), $content);

        // The other two fields are untouched by a failure in this one.
        $this->assertStringNotContainsString('id="password-error"', $content);
        $this->assertStringNotContainsString('id="password_confirmation-error"', $content);
    }

    public function test_a_weak_new_password_is_explained_against_its_own_field(): void
    {
        $content = $this->rejected([
            'current_password' => self::CURRENT,
            'password' => 'short',
            'password_confirmation' => 'short',
        ], 'password');

        $this->assertSame(1, substr_count($content, 'id="password-error"'));
        $this->assertStringContainsString('aria-describedby="password-help password-error"', $content);
        $this->assertStringNotContainsString('id="current_password-error"', $content);
    }

    /**
     * The application refuses a new password that is the one already set, and
     * says why in its own words. The page must not name the mechanism.
     */
    public function test_repeating_the_current_password_is_refused_in_plain_words(): void
    {
        $content = $this->rejected([
            'current_password' => self::CURRENT,
            'password' => self::CURRENT,
            'password_confirmation' => self::CURRENT,
        ], 'password');

        $message = 'Choose a password you have not used for this account.';

        $this->assertStringContainsString($message, $content);

        // Once against the field. The shell separately lists what went wrong at the
        // top of the page, which is the one place a message is allowed to repeat.
        preg_match('/<ul[^>]*id="password-error"[^>]*>.*?<\/ul>/s', $content, $matches);

        $this->assertNotEmpty($matches);
        $this->assertSame(1, substr_count($matches[0], $message));

        $this->assertStringNotContainsString('different:', $content);
        $this->assertStringNotContainsString('Password::', $content);
    }

    /**
     * The framework records a mismatch against the password field, but the
     * confirmation is what the visitor has to fix, so the message is shown there.
     */
    public function test_a_mismatch_is_explained_against_the_confirmation(): void
    {
        $content = $this->rejected([
            'current_password' => self::CURRENT,
            'password' => self::NEW,
            'password_confirmation' => 'something-else-entirely',
        ], 'password');

        $this->assertSame(1, substr_count($content, 'id="password_confirmation-error"'));
        $this->assertStringContainsString('aria-describedby="password_confirmation-help password_confirmation-error"', $content);

        // Not also left against the field it was recorded on.
        $this->assertStringNotContainsString('id="password-error"', $content);
        $this->assertStringContainsString('aria-describedby="password-help"', $content);
    }

    /**
     * A password that came back into the page would already have been read.
     */
    public function test_no_submitted_password_is_ever_reflected(): void
    {
        $account = User::factory()->create(['password' => self::CURRENT]);

        $content = (string) $this->actingAs($account)
            ->from('/account/security')
            ->followingRedirects()
            ->put('/account/security', [
                'current_password' => self::CURRENT,
                'password' => 'sh0rt',
                'password_confirmation' => 'an0ther-one',
            ])
            ->assertOk()
            ->getContent();

        foreach ([self::CURRENT, 'sh0rt', 'an0ther-one', 'not-the-current-password'] as $secret) {
            $this->assertStringNotContainsString($secret, $content);
        }

        foreach (['current_password', 'password', 'password_confirmation'] as $field) {
            preg_match('/<input[^>]*id="'.$field.'"[^>]*>/', $content, $matches);

            $this->assertNotEmpty($matches, "[{$field}] must render");
            $this->assertStringNotContainsString('value=', $matches[0], "[{$field}] must never carry a value");
        }
    }

    public function test_the_password_change_itself_still_works(): void
    {
        $account = User::factory()->create(['password' => self::CURRENT]);

        $this->actingAs($account)
            ->from('/account/security')
            ->put('/account/security', [
                'current_password' => self::CURRENT,
                'password' => self::NEW,
                'password_confirmation' => self::NEW,
            ])
            ->assertRedirect('/account/security')
            ->assertSessionHas('status', 'Your password has been changed.');

        $account->refresh();
        $this->assertTrue(Hash::check(self::NEW, $account->password));

        // Still signed in after the change.
        $this->assertAuthenticatedAs($account);

        // The new password signs in; the old one no longer does.
        $this->post('/logout');

        $this->post('/login', ['email' => $account->email, 'password' => self::NEW])
            ->assertRedirect('/dashboard');

        $this->post('/logout');

        $this->post('/login', ['email' => $account->email, 'password' => self::CURRENT])
            ->assertSessionHasErrors('email');
    }

    public function test_the_change_is_reported_once_by_the_shell(): void
    {
        $account = User::factory()->create(['password' => self::CURRENT]);

        $this->actingAs($account)
            ->put('/account/security', [
                'current_password' => self::CURRENT,
                'password' => self::NEW,
                'password_confirmation' => self::NEW,
            ])
            ->assertSessionHas('status', 'Your password has been changed.');

        $content = (string) $this->actingAs($account)
            ->withSession(['status' => 'Your password has been changed.'])
            ->get('/account/security')
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($content, 'Your password has been changed.'));
    }

    /**
     * The page used to promise that a change ended every other session. The
     * application does not do that, so the promise is gone and what actually
     * happens is said instead.
     */
    public function test_it_does_not_claim_other_sessions_are_ended(): void
    {
        $content = $this->page();

        foreach ([
            'ends every other session',
            'ends all sessions',
            'logged out of every device',
            'signed out of every device',
            'other sessions are ended',
        ] as $claim) {
            $this->assertStringNotContainsString($claim, $content);
        }

        $this->assertStringContainsString('You stay signed in here after a change.', $content);
    }

    public function test_it_reveals_no_internal_detail(): void
    {
        $content = $this->page();

        foreach ([
            'AccountController', 'UpdatePasswordRequest', 'Illuminate\\', 'Laravel', 'Password::',
            'different:current_password', 'confirmed', '@end', '@csrf', '@if', '{{ $', '{!!', '<x-',
            'current_password rule', 'Hash::',
        ] as $internal) {
            $this->assertStringNotContainsString($internal, $content);
        }
    }

    private function page(): string
    {
        return (string) $this->actingAs(User::factory()->create(['password' => self::CURRENT]))
            ->get('/account/security')
            ->assertOk()
            ->getContent();
    }

    /**
     * A rejected change, rendered as the browser would see it next: the browser
     * is sent back to the page, and the page is what gets asserted.
     */
    private function rejected(array $input, string $expectedField): string
    {
        // Following the redirect is what proves the error was recorded: an error
        // the session does not carry cannot appear on the page that follows.
        $content = (string) $this->actingAs(User::factory()->create(['password' => self::CURRENT]))
            ->from('/account/security')
            ->followingRedirects()
            ->put('/account/security', $input)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('-error"', $content, "[{$expectedField}] should have been reported");

        return $content;
    }
}

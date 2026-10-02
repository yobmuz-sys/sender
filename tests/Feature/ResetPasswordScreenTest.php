<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Reset password screen presentation.
 *
 * The reset itself is covered by AuthenticationTest. What is asserted here is the
 * page around it: that the token is carried back without ever being displayed,
 * that a password the visitor typed is never reflected into the response, and
 * that a rejected reset explains itself in the application's own words instead of
 * naming the mechanism that rejected it.
 */
class ResetPasswordScreenTest extends TestCase
{
    /**
     * A token-shaped value used only to prove the field carries whatever the route
     * supplied. Nothing here depends on the real format.
     */
    private const TOKEN = 'reset-token-value-for-screen-tests';

    public function test_the_reset_screen_offers_a_complete_form(): void
    {
        $this->get('/reset-password/'.self::TOKEN)
            ->assertOk()
            ->assertSee('action="'.route('password.store').'"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('id="email"', false)
            ->assertSee('type="email"', false)
            ->assertSee('autocomplete="username"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="password_confirmation"', false)
            ->assertSee('autocomplete="new-password"', false)
            ->assertSee('href="'.route('login').'"', false)
            ->assertSee('href="'.route('password.request').'"', false);
    }

    /**
     * The token is the capability that authorises this reset, so it must appear
     * exactly once: as the hidden field that carries it back.
     */
    public function test_the_reset_token_is_carried_but_never_displayed(): void
    {
        $content = (string) $this->get('/reset-password/'.self::TOKEN)->assertOk()->getContent();

        $this->assertSame(1, substr_count($content, self::TOKEN));
        $this->assertStringContainsString('<input type="hidden" name="token" value="'.self::TOKEN.'">', $content);

        // Nothing in the page may present the token as something a person reads.
        $this->assertStringNotContainsString('name="token" value="'.self::TOKEN.'" class', $content);
        $this->assertSame(1, substr_count($content, 'name="token"'));
    }

    public function test_both_password_fields_can_be_revealed_without_a_script(): void
    {
        $content = (string) $this->get('/reset-password/'.self::TOKEN)->assertOk()->getContent();

        // Progressive enhancement: the fields are password fields in the markup,
        // and the controls that reveal them are real buttons that describe them.
        foreach ([
            'data-show-label="Show password" data-hide-label="Hide password"',
            'data-show-label="Show password confirmation" data-hide-label="Hide password confirmation"',
            'aria-controls="password"',
            'aria-controls="password_confirmation"',
            'type="button"',
        ] as $expected) {
            $this->assertStringContainsString($expected, $content);
        }

        // Only the two buttons carry the marker with their target; the shell's
        // script mentions the attribute once more when it collects them.
        $this->assertSame(2, substr_count($content, 'data-password-toggle aria-controls'));
        $this->assertStringNotContainsString('Alpine', $content);
    }

    public function test_the_reset_screen_uses_the_public_authentication_shell(): void
    {
        $content = (string) $this->get('/reset-password/'.self::TOKEN)->assertOk()->getContent();

        $this->assertStringNotContainsString('aria-label="Breadcrumb"', $content);
        $this->assertStringNotContainsString('Version '.app()->version(), $content);
        $this->assertStringNotContainsString('href="'.route('dashboard').'"', $content);
    }

    public function test_the_reset_screen_exposes_no_implementation_detail(): void
    {
        $content = (string) $this->get('/reset-password/'.self::TOKEN)->assertOk()->getContent();

        foreach ([
            'NewPasswordController', 'Illuminate\\', 'App\\Models', 'passwords.token',
            'passwords.reset', 'PasswordReset', 'PasswordRule', 'APP_ENV', '/health', 'Redis',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $content);
        }
    }

    public function test_a_rejected_reset_is_explained_above_the_form_with_a_way_out(): void
    {
        $user = User::factory()->create();

        // An unusable token is the common case here: the link expired, or was
        // already used. The message the application supplies is rendered verbatim.
        $this->post('/reset-password', [
            'token' => self::TOKEN,
            'email' => $user->email,
            'password' => 'a-brand-new-secret',
            'password_confirmation' => 'a-brand-new-secret',
        ])->assertSessionHasErrors('email');

        $content = (string) $this->get('/reset-password/'.self::TOKEN)->assertOk()->getContent();

        $this->assertStringContainsString(__('passwords.token'), $content);
        $this->assertStringContainsString('role="alert"', $content);

        // A visitor holding an expired link cannot fix their email address, so the
        // outcome must not be left sitting under the input that cannot resolve it.
        $this->assertStringNotContainsString('id="email-error"', $content);
        $this->assertStringContainsString('href="'.route('password.request').'"', $content);
    }

    public function test_field_errors_stay_with_their_own_field(): void
    {
        $this->post('/reset-password', [
            'token' => self::TOKEN,
            'email' => 'not-an-address',
            'password' => 'a-brand-new-secret',
            'password_confirmation' => 'a-completely-different-one',
        ])->assertSessionHasErrors(['email', 'password']);

        $content = (string) $this->get('/reset-password/'.self::TOKEN)->assertOk()->getContent();

        $this->assertStringContainsString(__('validation.email', ['attribute' => 'email']), $content);
        $this->assertStringContainsString(__('validation.confirmed', ['attribute' => 'password']), $content);
        $this->assertStringContainsString('aria-describedby="email-help email-error"', $content);
        $this->assertStringContainsString('aria-describedby="password-help password-error"', $content);
        $this->assertStringContainsString('aria-invalid="true"', $content);
    }

    public function test_a_rejected_submission_keeps_the_email_and_never_the_passwords(): void
    {
        $this->post('/reset-password', [
            'token' => self::TOKEN,
            'email' => 'ada@example.com',
            'password' => 'a-brand-new-secret',
            'password_confirmation' => 'something-else-entirely',
        ])->assertSessionHasErrors('password');

        $content = (string) $this->get('/reset-password/'.self::TOKEN)->assertOk()->getContent();

        $this->assertStringContainsString('value="ada@example.com"', $content);
        $this->assertStringNotContainsString('a-brand-new-secret', $content);
        $this->assertStringNotContainsString('something-else-entirely', $content);

        foreach (['password', 'password_confirmation'] as $field) {
            preg_match('/<input[^>]*name="'.$field.'"[^>]*>/', $content, $match);

            $this->assertStringNotContainsString('value=', $match[0] ?? 'missing');
        }
    }

    public function test_a_successful_reset_still_asks_the_visitor_to_sign_in_again(): void
    {
        $user = User::factory()->create();
        $token = app('auth.password.broker')->createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-brand-new-secret',
            'password_confirmation' => 'a-brand-new-secret',
        ])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertTrue(Hash::check('a-brand-new-secret', (string) $user->fresh()->password));
    }
}

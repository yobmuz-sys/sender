<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

/**
 * Register screen presentation.
 *
 * Registration behaviour is covered by AuthenticationTest; what is asserted here
 * is that the screen presents it honestly: the fields the backend actually
 * accepts are the fields on screen, a rejected submission says which field was
 * rejected in words a person can act on, and a password the visitor just typed
 * is never reflected back into the page.
 */
class RegisterScreenTest extends TestCase
{
    public function test_the_registration_screen_offers_a_complete_form(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertSee('action="'.route('register').'"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('id="name"', false)
            ->assertSee('name="name"', false)
            ->assertSee('autocomplete="name"', false)
            ->assertSee('id="email"', false)
            ->assertSee('type="email"', false)
            ->assertSee('autocomplete="username"', false)
            ->assertSee('id="password"', false)
            ->assertSee('name="password"', false)
            ->assertSee('autocomplete="new-password"', false)
            ->assertSee('id="password_confirmation"', false)
            ->assertSee('name="password_confirmation"', false)
            ->assertSee('href="'.route('login').'"', false);
    }

    /**
     * The screen asks for exactly what the backend accepts. A field the request
     * would silently discard is worse than a missing one: it looks like it was
     * recorded and was not.
     */
    public function test_no_field_is_asked_for_that_the_backend_does_not_accept(): void
    {
        $content = (string) $this->get('/register')->assertOk()->getContent();

        foreach (['company', 'phone', 'role', 'plan', 'billing', 'password_current'] as $unexpected) {
            $this->assertStringNotContainsString('name="'.$unexpected.'"', $content);
        }
    }

    public function test_the_registration_screen_never_shows_the_application_shell(): void
    {
        $content = (string) $this->get('/register')->assertOk()->getContent();

        $this->assertStringNotContainsString('aria-label="Breadcrumb"', $content);
        $this->assertStringNotContainsString('Version '.app()->version(), $content);
        $this->assertStringNotContainsString('href="'.route('dashboard').'"', $content);
    }

    public function test_the_registration_screen_exposes_no_implementation_detail(): void
    {
        $content = (string) $this->get('/register')->assertOk()->getContent();

        foreach ([
            'RegisterRequest', 'RegisteredUserController', 'Illuminate\\', 'App\\Models',
            'APP_ENV', 'AUTH_PASSWORD', '/health', 'Redis', 'ValidationException',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $content);
        }

        // Rule names are implementation detail dressed as an explanation. They
        // only reach the page if the error bag is printed instead of its message.
        foreach (['unique:users', 'Password::defaults', 'lowercase', 'max:255'] as $ruleName) {
            $this->assertStringNotContainsString($ruleName, $content);
        }
    }

    public function test_a_rejected_submission_reports_each_field_on_its_own_input(): void
    {
        $this->post('/register', ['name' => '', 'email' => 'not-an-address', 'password' => ''])
            ->assertSessionHasErrors(['name', 'email', 'password']);

        $content = (string) $this->get('/register')->assertOk()->getContent();

        $this->assertStringContainsString('id="name-error"', $content);
        $this->assertStringContainsString('aria-describedby="name-error"', $content);
        $this->assertStringContainsString('aria-describedby="email-error"', $content);

        // The password field describes both its help text and its error, so the
        // two ids are asserted as the pair that is actually rendered.
        $this->assertStringContainsString('aria-describedby="password-help password-error"', $content);

        foreach (['name', 'email', 'password'] as $field) {
            $this->assertStringContainsString('id="'.$field.'-error"', $content);
        }

        $this->assertStringContainsString('aria-invalid="true"', $content);
    }

    public function test_a_rejected_submission_keeps_the_name_and_email_but_never_the_password(): void
    {
        $this->post('/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'not-the-same-value',
        ])->assertSessionHasErrors('password');

        $content = (string) $this->get('/register')->assertOk()->getContent();

        $this->assertStringContainsString('value="Ada Lovelace"', $content);
        $this->assertStringContainsString('value="ada@example.com"', $content);

        // Neither password field may ever be reflected back, in any form.
        $this->assertStringNotContainsString('correct-horse-battery', $content);
        $this->assertStringNotContainsString('not-the-same-value', $content);

        preg_match('/<input[^>]*name="password"[^>]*>/', $content, $passwordField);
        $this->assertStringNotContainsString('value=', $passwordField[0] ?? '');
    }

    public function test_a_mismatched_confirmation_is_explained_in_plain_words(): void
    {
        $this->post('/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'something-else-entirely',
        ])->assertSessionHasErrors('password');

        $content = (string) $this->get('/register')->assertOk()->getContent();

        $this->assertStringContainsString(__('validation.confirmed', ['attribute' => 'password']), $content);
    }

    public function test_an_address_already_in_use_is_reported_without_extra_detail(): void
    {
        $user = User::factory()->create();

        $this->post('/register', [
            'name' => 'Impostor',
            'email' => $user->email,
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])->assertSessionHasErrors('email');

        $content = (string) $this->get('/register')->assertOk()->getContent();

        $this->assertStringContainsString(__('validation.unique', ['attribute' => 'email']), $content);

        // Nothing beyond the validation sentence: no account state, no role, no
        // confirmation status, nothing that helps someone enumerate accounts.
        $this->assertStringNotContainsString($user->name, $content);
    }

    public function test_the_screen_tells_the_visitor_that_confirmation_comes_next(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertSee('confirm your email', false);
    }

    public function test_a_successful_registration_still_lands_on_the_confirmation_notice(): void
    {
        $this->post('/register', [
            'name' => 'Grace Hopper',
            'email' => 'grace@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])->assertRedirect(route('verification.notice'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'grace@example.com']);
    }
}

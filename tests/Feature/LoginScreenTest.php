<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Login screen presentation.
 *
 * Authentication behaviour is covered by AuthenticationTest; what is asserted
 * here is that the screen presents it correctly. Two properties matter most and
 * are easy to lose in a redesign: a failed sign-in must stay generic, because
 * anything more specific tells a stranger which addresses exist; and the page
 * must not carry the authenticated application shell, which would describe an
 * internal workspace to a visitor standing outside it.
 */
class LoginScreenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('test');
    }

    public function test_the_login_screen_offers_a_complete_sign_in_form(): void
    {
        $response = $this->get('/login');

        $response->assertOk()
            ->assertSee('action="'.route('login').'"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('id="email"', false)
            ->assertSee('name="email"', false)
            ->assertSee('type="email"', false)
            ->assertSee('autocomplete="username"', false)
            ->assertSee('id="password"', false)
            ->assertSee('name="password"', false)
            ->assertSee('type="password"', false)
            ->assertSee('autocomplete="current-password"', false)
            ->assertSee('name="remember"', false)
            ->assertSee('href="'.route('password.request').'"', false)
            ->assertSee('href="'.route('register').'"', false);
    }

    public function test_the_login_screen_never_shows_the_application_shell(): void
    {
        $content = (string) $this->get('/login')->assertOk()->getContent();

        // No breadcrumbs, no build version, no authenticated navigation: the
        // public shell must not leak the workspace into a public screen.
        $this->assertStringNotContainsString('aria-label="Breadcrumb"', $content);
        $this->assertStringNotContainsString('Version '.app()->version(), $content);
        $this->assertStringNotContainsString('href="'.route('dashboard').'"', $content);
    }

    public function test_the_login_screen_exposes_no_implementation_detail(): void
    {
        $content = (string) $this->get('/login')->assertOk()->getContent();

        foreach (['LoginRequest', 'Illuminate\\', 'app/Domain', 'APP_ENV', '/health', 'Redis'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $content);
        }
    }

    public function test_a_failed_sign_in_is_reported_once_as_a_whole_form_problem(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');

        $content = (string) $this->get('/login')->assertOk()->getContent();

        // The generic message belongs in the alert, so it must not also be
        // rendered under the field it is attached to in the session.
        $this->assertStringContainsString(__('auth.failed'), $content);
        $this->assertStringContainsString('role="alert"', $content);
        $this->assertStringNotContainsString('aria-describedby="email-error"', $content);
    }

    public function test_an_unknown_address_and_a_known_one_fail_identically(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        $known = $this->get('/login')->assertOk()->getContent();

        RateLimiter::clear('test');

        $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password']);
        $unknown = $this->get('/login')->assertOk()->getContent();

        $this->assertSame(
            $this->messagesFrom($known),
            $this->messagesFrom($unknown),
            'the screen must not reveal whether an address exists',
        );
    }

    public function test_a_field_error_stays_with_its_field_and_keeps_the_submitted_address(): void
    {
        $this->post('/login', ['email' => 'not-an-address', 'password' => ''])
            ->assertSessionHasErrors(['email', 'password']);

        $content = (string) $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('value="not-an-address"', $content);
        $this->assertStringContainsString('aria-describedby="email-error"', $content);
        $this->assertStringContainsString('aria-describedby="password-error"', $content);
        $this->assertStringContainsString('aria-invalid="true"', $content);
    }

    public function test_a_rate_limited_sign_in_shows_the_human_readable_notice(): void
    {
        $user = User::factory()->create();

        for ($attempt = 0; $attempt <= 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $this->assertGuest();

        $content = (string) $this->get('/login')->assertOk()->getContent();

        // Matched against the framework's own translation rather than a copied
        // sentence, so this keeps proving the right thing if the wording changes.
        $this->assertStringContainsString(
            Str::before((string) __('auth.throttle'), ':seconds'),
            $content,
        );
    }

    /**
     * The visible error text on the rendered page, in order.
     */
    private function messagesFrom(string $content): array
    {
        preg_match_all('/<(?:li|p)[^>]*>([^<]+)<\/(?:li|p)>/', $content, $matches);

        return array_values(array_filter(
            array_map('trim', $matches[1] ?? []),
            static fn (string $line): bool => Str::contains($line, ['credentials', 'attempts', 'email', 'required', 'valid']),
        ));
    }
}

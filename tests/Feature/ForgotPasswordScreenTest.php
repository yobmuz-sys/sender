<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Forgot password screen presentation.
 *
 * The reset request itself is covered by AuthenticationTest. What matters here is
 * that the screen says the same thing to a visitor whether or not the address
 * belongs to an account: the endpoint answers identically on purpose, and a page
 * that added reassurance to one case and not the other would undo that entirely.
 */
class ForgotPasswordScreenTest extends TestCase
{
    public function test_the_recovery_screen_offers_a_complete_form(): void
    {
        $this->get('/forgot-password')
            ->assertOk()
            ->assertSee('action="'.route('password.email').'"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('id="email"', false)
            ->assertSee('name="email"', false)
            ->assertSee('type="email"', false)
            ->assertSee('autocomplete="username"', false)
            ->assertSee('href="'.route('login').'"', false)
            ->assertSee('href="'.route('register').'"', false);
    }

    public function test_the_recovery_screen_uses_the_public_authentication_shell(): void
    {
        $content = (string) $this->get('/forgot-password')->assertOk()->getContent();

        // No workspace chrome: this is a public door, not a page inside the app.
        $this->assertStringNotContainsString('aria-label="Breadcrumb"', $content);
        $this->assertStringNotContainsString('Version '.app()->version(), $content);
        $this->assertStringNotContainsString('href="'.route('dashboard').'"', $content);
    }

    public function test_the_recovery_screen_exposes_no_implementation_detail(): void
    {
        $content = (string) $this->get('/forgot-password')->assertOk()->getContent();

        foreach ([
            'PasswordResetLinkController', 'Illuminate\\', 'passwords.sent',
            'RESET_LINK_SENT', 'APP_ENV', '/health', 'Redis', 'DatabaseToken',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $content);
        }
    }

    public function test_the_confirmation_comes_from_the_reset_response_and_stays_generic(): void
    {
        Notification::fake();

        $this->post('/forgot-password', ['email' => 'nobody@example.com'])
            ->assertSessionHas('status');

        $content = (string) $this->get('/forgot-password')->assertOk()->getContent();

        $this->assertStringContainsString(__('passwords.sent'), $content);
        $this->assertStringContainsString('role="status"', $content);
    }

    /**
     * A known address and an unknown one must produce the same visible result, or
     * the screen becomes an account oracle no matter how careful the endpoint is.
     */
    public function test_a_known_and_an_unknown_address_produce_the_same_screen(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);
        $known = $this->messagesOn((string) $this->get('/forgot-password')->assertOk()->getContent());

        $this->flushSession();

        $this->post('/forgot-password', ['email' => 'nobody@example.com']);
        $unknown = $this->messagesOn((string) $this->get('/forgot-password')->assertOk()->getContent());

        $this->assertSame($known, $unknown);
    }

    public function test_a_rejected_address_is_explained_under_its_own_field(): void
    {
        $this->post('/forgot-password', ['email' => 'not-an-address'])
            ->assertSessionHasErrors('email');

        $content = (string) $this->get('/forgot-password')->assertOk()->getContent();

        $this->assertStringContainsString(__('validation.email', ['attribute' => 'email']), $content);
        $this->assertStringContainsString('id="email-error"', $content);
        $this->assertStringContainsString('aria-describedby="email-error"', $content);
        $this->assertStringContainsString('aria-invalid="true"', $content);

        // One place only: the message must not also be repeated as a notice.
        $this->assertSame(1, substr_count($content, __('validation.email', ['attribute' => 'email'])));
    }

    public function test_a_missing_address_is_explained_and_the_typed_one_is_kept(): void
    {
        $this->post('/forgot-password', ['email' => ''])
            ->assertSessionHasErrors('email');

        $content = (string) $this->get('/forgot-password')->assertOk()->getContent();

        $this->assertStringContainsString(__('validation.required', ['attribute' => 'email']), $content);
        $this->assertStringContainsString('aria-invalid="true"', $content);

        $this->post('/forgot-password', ['email' => 'typo-at-example'])
            ->assertSessionHasErrors('email');

        $kept = (string) $this->get('/forgot-password')->assertOk()->getContent();

        $this->assertStringContainsString('value="typo-at-example"', $kept);
    }

    /**
     * Visible message text on the rendered page, in order.
     */
    private function messagesOn(string $content): array
    {
        preg_match_all('/<(?:li|p|span)[^>]*>([^<]+)<\/(?:li|p|span)>/', $content, $matches);

        return array_values(array_filter(
            array_map('trim', $matches[1] ?? []),
            static fn (string $line): bool => $line !== ''
                && ! str_contains($line, 'Signed in')
                && ! str_contains($line, 'Reset your password'),
        ));
    }
}

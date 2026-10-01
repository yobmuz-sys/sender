<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('test');
    }

    public function test_the_login_screen_can_be_rendered(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_a_visitor_can_register_and_is_logged_in(): void
    {
        Event::fake([Registered::class]);

        $response = $this->post('/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);

        Event::assertDispatched(Registered::class);
    }

    public function test_a_registered_account_is_never_staff(): void
    {
        $this->post('/register', [
            'name' => 'Mallory',
            'email' => 'mallory@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            // A self-granted role in the request body must be ignored.
            'role' => 'super_admin',
        ]);

        $this->assertSame(Role::User, User::where('email', 'mallory@example.com')->sole()->role);
    }

    public function test_registration_rejects_a_weak_password(): void
    {
        $this->post('/register', [
            'name' => 'Weak',
            'email' => 'weak@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->assertGuest();
    }

    public function test_a_user_can_log_in_with_valid_credentials(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_user_cannot_log_in_with_an_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_repeated_failures_are_throttled(): void
    {
        $user = User::factory()->create();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);
        }

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->assertSame(
            5,
            RateLimiter::attempts($user->email.'|127.0.0.1'),
            'all failed attempts should be counted against the throttle key',
        );
        $this->assertLessThanOrEqual(60, RateLimiter::availableIn($user->email.'|127.0.0.1'));
    }

    public function test_a_user_can_log_out(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/logout')
            ->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_guests_are_redirected_from_protected_pages(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_passwords_are_hashed_and_never_stored_in_plain_text(): void
    {
        $this->post('/register', [
            'name' => 'Grace',
            'email' => 'grace@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ]);

        $stored = (string) User::where('email', 'grace@example.com')->value('password');

        $this->assertNotSame('correct-horse-battery', $stored);
        $this->assertTrue(Hash::check('correct-horse-battery', $stored));
    }

    public function test_a_password_reset_link_can_be_requested_for_an_unknown_address(): void
    {
        $this->post('/forgot-password', ['email' => 'nobody@example.com'])
            ->assertSessionHas('status');

        $this->assertGuest();
    }

    public function test_a_password_can_be_reset_with_a_valid_token(): void
    {
        $user = User::factory()->create();
        $token = app('auth.password.broker')->createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-brand-new-secret',
            'password_confirmation' => 'a-brand-new-secret',
        ])->assertRedirect('/login');

        $this->assertTrue(
            Hash::check('a-brand-new-secret', (string) $user->fresh()->password)
        );
    }

    public function test_a_password_reset_rejects_an_invalid_token(): void
    {
        $user = User::factory()->create();

        $this->post('/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'a-brand-new-secret',
            'password_confirmation' => 'a-brand-new-secret',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('password', (string) $user->fresh()->password));
    }
}

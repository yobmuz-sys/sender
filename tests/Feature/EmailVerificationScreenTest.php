<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

/**
 * Email verification screen presentation.
 *
 * The verification flow itself is covered by AuthenticationTest. What matters here
 * is what this page says, because it is the first screen a new account sees after
 * signing up, and the old version told that person to run a command on a server
 * they do not have access to. A page describing the inside of the product to
 * someone outside it is not a rough edge; it is the wrong page.
 */
class EmailVerificationScreenTest extends TestCase
{
    public function test_a_guest_is_redirected_away_from_the_notice(): void
    {
        $this->get('/email/verify')->assertRedirect('/login');
    }

    public function test_an_unconfirmed_account_sees_the_notice(): void
    {
        $this->actingAs(User::factory()->unverified()->create())
            ->get('/email/verify')
            ->assertOk();
    }

    public function test_a_confirmed_account_is_sent_to_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/email/verify')
            ->assertRedirect(route('dashboard'));
    }

    public function test_the_notice_offers_both_actions_and_a_token(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get('/email/verify')
            ->assertOk()
            ->assertSee('name="_token"', false)
            ->assertSee('method="POST" action="'.route('verification.send').'"', false)
            ->assertSee('method="POST" action="'.route('logout').'"', false);
    }

    public function test_the_notice_shows_the_address_and_nothing_about_the_account(): void
    {
        $user = User::factory()->unverified()->create();

        $content = (string) $this->actingAs($user)->get('/email/verify')->assertOk()->getContent();

        $this->assertStringContainsString($user->email, $content);

        // Only this account, and only its address. An identifier would be both
        // pointless to the reader and one more thing to leak.
        $this->assertStringNotContainsString('/users/'.$user->id, $content);
        $this->assertStringNotContainsString('value="'.$user->id.'"', $content);
        $this->assertStringNotContainsString($user->name, $content);
    }

    /**
     * The page must never describe the machinery behind delivery.
     */
    public function test_the_notice_exposes_no_implementation_detail(): void
    {
        $content = (string) $this->actingAs(User::factory()->unverified()->create())
            ->get('/email/verify')
            ->assertOk()
            ->getContent();

        foreach ([
            'php artisan',
            'sender:verify-smtp',
            'artisan',
            'config(',
            'auth.verification.expire',
            'Laravel',
            'Mailer',
            'SMTP',
            'smtp',
            'operator',
            'unconfigured',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $content);
        }

        $this->assertStringNotContainsString('<code', $content);
    }

    public function test_the_notice_never_shows_the_application_shell(): void
    {
        $content = (string) $this->actingAs(User::factory()->unverified()->create())
            ->get('/email/verify')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('aria-label="Breadcrumb"', $content);
        $this->assertStringNotContainsString('Version '.app()->version(), $content);
        $this->assertStringNotContainsString('href="'.route('dashboard').'"', $content);
    }

    public function test_the_notice_has_exactly_one_heading(): void
    {
        $content = (string) $this->actingAs(User::factory()->unverified()->create())
            ->get('/email/verify')
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($content, '<h1'));
    }

    /**
     * A fresh confirmation is reported once, in a region a screen reader announces
     * without the visitor having to go looking for it.
     */
    public function test_a_resent_confirmation_is_reported_once_in_a_status_region(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->post('/email/verification-notification')
            ->assertRedirect()
            ->assertSessionHas('status');

        $content = (string) $this->get('/email/verify')->assertOk()->getContent();

        $this->assertStringContainsString('role="status"', $content);
        $this->assertSame(1, substr_count($content, 'A fresh confirmation link has been sent to your address.'));
    }

    /**
     * The resend endpoint is scoped to the signed-in account and throttled. The
     * screen must not offer a way around either.
     */
    public function test_the_resend_action_requires_the_signed_in_account(): void
    {
        // A guest has no account to send a link to.
        $this->post('/email/verification-notification')->assertRedirect('/login');

        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->post('/email/verification-notification')
            ->assertRedirect()
            ->assertSessionHas('status');

        // Asking for another message is not a sign-out, and does not hand the
        // session to anybody else.
        $this->assertAuthenticatedAs($user);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Mail\SmtpAccount;
use App\Domain\Mail\SmtpAccountStatus;
use App\Domain\Mail\SmtpAuthMode;
use App\Domain\Mail\SmtpEncryption;
use App\Domain\Mail\SmtpManagementMode;
use App\Domain\Mail\SmtpProvider;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Permission;
use App\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;

/**
 * The administrative surface for tenants' transports.
 *
 * The load-bearing test is {@see test_support_can_read_metadata_but_never_manage}:
 * a support role that could change a credential would be able to redirect a
 * tenant's mail, and one that could assign one could change whose identity they
 * send as. Both are separated from reading the metadata a support question needs.
 */
class SmtpAccountAdminTest extends MailTestCase
{
    public function test_the_account_pages_render_for_a_permitted_administrator(): void
    {
        $admin = User::factory()->role(Role::SuperAdmin)->create();
        $user = User::factory()->create();
        $account = $this->accountFor($user);

        foreach ([
            route('admin.smtp.accounts.index'),
            route('admin.smtp.accounts.create'),
            route('admin.smtp.accounts.show', $account),
            route('admin.smtp.accounts.edit', $account),
            route('admin.users.smtp', $user),
            route('admin.deliverability.index'),
        ] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }

    public function test_every_account_route_is_named_and_exists(): void
    {
        foreach ([
            'admin.smtp.accounts.index',
            'admin.smtp.accounts.create',
            'admin.smtp.accounts.store',
            'admin.smtp.accounts.show',
            'admin.smtp.accounts.edit',
            'admin.smtp.accounts.update',
            'admin.smtp.accounts.destroy',
            'admin.smtp.accounts.assign',
            'admin.smtp.accounts.status',
            'admin.smtp.accounts.verify',
            'admin.smtp.accounts.send-test',
            'admin.users.smtp',
            'admin.deliverability.index',
        ] as $name) {
            $this->assertNotNull(
                \Illuminate\Support\Facades\Route::getRoutes()->getByName($name),
                "Route {$name} must be named.",
            );
        }
    }

    public function test_a_customer_is_refused_the_administrative_account_surface(): void
    {
        $customer = User::factory()->role(Role::User)->create();
        $account = $this->accountFor(User::factory()->create());

        foreach ([
            route('admin.smtp.accounts.index'),
            route('admin.smtp.accounts.create'),
            route('admin.smtp.accounts.show', $account),
            route('admin.deliverability.index'),
        ] as $url) {
            $this->actingAs($customer)->get($url)->assertForbidden();
        }
    }

    public function test_support_can_read_metadata_but_never_manage(): void
    {
        $support = User::factory()->role(Role::Support)->create();
        $user = User::factory()->create();
        $account = $this->accountFor($user);

        // Reads the answers to a support question.
        $this->actingAs($support)->get(route('admin.smtp.accounts.index'))->assertOk();
        $this->actingAs($support)->get(route('admin.smtp.accounts.show', $account))->assertOk();
        $this->actingAs($support)->get(route('admin.deliverability.index'))->assertOk();

        // Cannot change anything about a transport.
        $this->actingAs($support)->get(route('admin.smtp.accounts.create'))->assertForbidden();
        $this->actingAs($support)->get(route('admin.smtp.accounts.edit', $account))->assertForbidden();
        $this->actingAs($support)->delete(route('admin.smtp.accounts.destroy', $account))->assertForbidden();
        $this->actingAs($support)
            ->post(route('admin.smtp.accounts.status', [$account, 'disabled']))
            ->assertForbidden();
        $this->actingAs($support)
            ->post(route('admin.smtp.accounts.assign', $account), ['user_id' => $support->getKey()])
            ->assertForbidden();

        $this->assertDatabaseHas('smtp_accounts', [
            'id' => $account->getKey(),
            'status' => SmtpAccountStatus::Unverified->value,
        ]);
    }

    public function test_a_support_page_never_renders_a_credential(): void
    {
        $support = User::factory()->role(Role::Support)->create();
        $account = $this->accountFor(User::factory()->create());

        foreach ([
            route('admin.smtp.accounts.index'),
            route('admin.smtp.accounts.show', $account),
        ] as $url) {
            $response = $this->actingAs($support)->get($url);

            $response->assertOk();
            $this->assertStringNotContainsString('app-password-value', $response->getContent());
        }
    }

    public function test_an_administrator_can_create_a_transport_for_a_user(): void
    {
        $admin = User::factory()->role(Role::SuperAdmin)->create();
        $user = User::factory()->create();

        $this->actingAs($admin)->post(route('admin.smtp.accounts.store'), [
            'user_id' => $user->getKey(),
            'label' => 'Platform relay',
            'provider' => SmtpProvider::Custom->value,
            'management_mode' => SmtpManagementMode::AdminManaged->value,
            'host' => 'relay.example.com',
            'port' => 587,
            'encryption' => SmtpEncryption::StartTls->value,
            'auth_mode' => SmtpAuthMode::Password->value,
            'username' => 'alice@example.com',
            'secret' => 'operator-password',
            'from_address' => 'alice@example.com',
        ])->assertRedirect();

        $account = SmtpAccount::query()->firstOrFail();

        $this->assertSame($user->getKey(), $account->user_id);
        $this->assertSame(SmtpManagementMode::AdminManaged, $account->management_mode);
        $this->assertFalse($account->ownerMayEdit());
        $this->assertStringNotContainsString(
            'operator-password',
            (string) $this->rawSecret($account),
        );
    }

    public function test_an_administrator_transport_is_visible_to_its_owner_but_not_editable(): void
    {
        $admin = User::factory()->role(Role::SuperAdmin)->create();
        $user = User::factory()->create();

        $this->actingAs($admin)->post(route('admin.smtp.accounts.store'), [
            'user_id' => $user->getKey(),
            'label' => 'Platform relay',
            'provider' => SmtpProvider::Custom->value,
            'host' => 'relay.example.com',
            'port' => 587,
            'encryption' => SmtpEncryption::StartTls->value,
            'auth_mode' => SmtpAuthMode::Password->value,
            'username' => 'alice@example.com',
            'secret' => 'operator-password',
            'from_address' => 'alice@example.com',
        ])->assertRedirect();

        $account = SmtpAccount::query()->firstOrFail();

        $response = $this->actingAs($user)->get(route('account.smtp.show', $account));

        $response->assertOk();
        // The forced transport is visible to the affected user, which is the
        // point: a tenant who cannot change a relay they send through is owed
        // the knowledge that it exists and who set it.
        $this->assertStringContainsString('relay.example.com', $response->getContent());
        $this->assertStringContainsString('Managed by platform staff', $response->getContent());
        $this->assertStringNotContainsString('operator-password', $response->getContent());
    }

    public function test_reassigning_moves_the_row_rather_than_copying_the_credential(): void
    {
        $admin = User::factory()->role(Role::SuperAdmin)->create();
        $first = User::factory()->create();
        $second = User::factory()->create();

        $account = $this->accountFor($first);
        $before = $this->rawSecret($account);

        $this->actingAs($admin)
            ->post(route('admin.smtp.accounts.assign', $account), ['user_id' => $second->getKey()])
            ->assertRedirect();

        $this->assertDatabaseCount('smtp_accounts', 1);
        $this->assertSame($second->getKey(), $account->fresh()->user_id);
        $this->assertSame(
            $before,
            $this->rawSecret($account),
            'One credential must never exist in two rows.',
        );
    }

    public function test_an_administrator_can_switch_a_transport_off_and_on(): void
    {
        $admin = User::factory()->role(Role::SuperAdmin)->create();
        $account = $this->accountFor(User::factory()->create());
        $account->markVerified(3600);

        $this->actingAs($admin)
            ->post(route('admin.smtp.accounts.status', [$account, 'disabled']))
            ->assertRedirect();

        $this->assertSame(SmtpAccountStatus::Disabled, $account->fresh()->status);

        $this->actingAs($admin)
            ->post(route('admin.smtp.accounts.status', [$account, 'unverified']))
            ->assertRedirect();

        $restored = $account->fresh();

        $this->assertSame(SmtpAccountStatus::Unverified, $restored->status);
        $this->assertNull(
            $restored->verified_at,
            'Switching a transport back on is a decision to use it, not evidence that it works.',
        );
    }

    public function test_an_operational_status_cannot_be_forged(): void
    {
        $admin = User::factory()->role(Role::SuperAdmin)->create();
        $account = $this->accountFor(User::factory()->create());

        $this->actingAs($admin)
            ->post(route('admin.smtp.accounts.status', [$account, 'ready']))
            ->assertSessionHasErrors('status');

        $this->assertFalse(
            $account->fresh()->effectiveStatus()->isUsable(),
            'READY is an outcome of a verification, not a field an operator sets.',
        );
    }

    public function test_assignment_is_never_granted_without_manage(): void
    {
        // Assign decides whose address a tenant's mail appears to come from. A
        // role that could assign without being able to manage would be able to
        // redirect a transport it could not otherwise correct.
        foreach (Role::cases() as $role) {
            $grants = $role->permissions();

            if (in_array(Permission::MAIL_ACCOUNTS_ASSIGN, $grants, true)) {
                $this->assertContains(
                    Permission::MAIL_ACCOUNTS_MANAGE,
                    $grants,
                    "Role {$role->value} may assign only if it may also manage.",
                );
            }
        }

        $user = User::factory()->create();
        $other = User::factory()->create();
        $account = $this->accountFor($user);

        $this->actingAs(User::factory()->role(Role::SuperAdmin)->create())
            ->post(route('admin.smtp.accounts.assign', $account), ['user_id' => $other->getKey()])
            ->assertRedirect();

        $this->assertSame($other->getKey(), $account->fresh()->user_id);
    }

    public function test_verification_endpoints_are_rate_limited(): void
    {
        $admin = User::factory()->role(Role::SuperAdmin)->create();
        $account = $this->accountFor(User::factory()->create());

        // The configured throttle, asserted rather than the number of allowed
        // attempts, so the test states the policy instead of restating it.
        $send = \Illuminate\Support\Facades\Route::getRoutes()->getByName('account.smtp.send-test');
        $connection = \Illuminate\Support\Facades\Route::getRoutes()->getByName('account.smtp.verify');

        $this->assertNotNull($send);
        $this->assertNotNull($connection);

        $sendThrottle = $this->throttleOn($send);
        $connectionThrottle = $this->throttleOn($connection);

        $this->assertNotNull($sendThrottle, 'Sending a test message must be rate limited.');
        $this->assertNotNull($connectionThrottle, 'Probing a connection must be rate limited.');

        // And the send action is bounded far tighter than the connection probe,
        // because it consumes the customer's provider quota. Compared as a
        // rate, since the two windows differ.
        [$sendLimit, $sendWindow] = explode(',', $sendThrottle);
        [$connectionLimit, $connectionWindow] = explode(',', $connectionThrottle);

        $this->assertLessThan(
            (int) $connectionLimit / (int) $connectionWindow,
            (int) $sendLimit / (int) $sendWindow,
            'Sending a test message must be permitted less often than probing a connection.',
        );

        unset($admin);
    }

    /**
     * The throttle parameter string on a route, or null when it has none.
     */
    private function throttleOn(Route $route): ?string
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (str_starts_with($middleware, 'throttle:')) {
                return substr($middleware, strlen('throttle:'));
            }
        }

        return null;
    }

    public function test_the_permissions_exist_and_are_grouped(): void
    {
        foreach ([
            Permission::MAIL_ACCOUNTS_VIEW,
            Permission::MAIL_ACCOUNTS_MANAGE,
            Permission::MAIL_ACCOUNTS_ASSIGN,
            Permission::DELIVERABILITY_VIEW,
        ] as $permission) {
            $this->assertContains($permission, Permission::all());
        }

        $this->assertArrayHasKey('Mail', Permission::groups());

        // And every permission has a registered gate, so a typo in a controller
        // fails during testing rather than silently denying in production.
        foreach (Permission::all() as $permission) {
            $this->assertTrue(
                Gate::has($permission),
                "Gate {$permission} must be registered.",
            );
        }
    }
}

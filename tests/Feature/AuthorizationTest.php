<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Enums\Role;
use App\Domain\Users\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    public function test_every_declared_permission_is_registered_as_a_gate(): void
    {
        foreach (Permission::all() as $permission) {
            $this->assertTrue(
                Gate::has($permission),
                "Gate '{$permission}' is not registered, so a typo would fail silently in production.",
            );
        }
    }

    public function test_a_super_admin_holds_every_permission(): void
    {
        $user = User::factory()->role(Role::SuperAdmin)->create();

        foreach (Permission::all() as $permission) {
            $this->assertTrue($user->can($permission), "Super admin failed '{$permission}'");
        }
    }

    public function test_a_plain_user_holds_no_administrative_permission(): void
    {
        $user = User::factory()->create();

        foreach (Permission::all() as $permission) {
            $this->assertFalse($user->can($permission), "A regular account must not hold '{$permission}'");
        }

        $this->assertFalse($user->isStaff());
    }

    public function test_support_staff_is_read_only(): void
    {
        $user = User::factory()->role(Role::Support)->create();

        $this->assertTrue($user->can(Permission::USERS_VIEW));
        $this->assertFalse($user->can(Permission::USERS_SUSPEND));
        $this->assertFalse($user->can(Permission::JOBS_MANAGE));
    }

    public function test_operations_staff_can_pause_campaigns_but_not_manage_users(): void
    {
        $user = User::factory()->role(Role::Operations)->create();

        $this->assertTrue($user->can(Permission::CAMPAIGNS_PAUSE));
        $this->assertTrue($user->can(Permission::JOBS_MANAGE));
        $this->assertFalse($user->can(Permission::USERS_EDIT));
    }

    public function test_billing_staff_manages_plans_but_not_the_system(): void
    {
        $user = User::factory()->role(Role::Billing)->create();

        $this->assertTrue($user->can(Permission::PLANS_MANAGE));
        $this->assertFalse($user->can(Permission::SYSTEM_VIEW));
    }

    public function test_an_admin_cannot_change_system_configuration(): void
    {
        $user = User::factory()->role(Role::Admin)->create();

        $this->assertTrue($user->can(Permission::SYSTEM_VIEW));
        $this->assertFalse($user->can(Permission::SYSTEM_MANAGE));
    }

    public function test_the_role_is_casted_to_the_enum(): void
    {
        $this->assertInstanceOf(Role::class, User::factory()->create()->role);
    }

    public function test_the_diagnostics_page_requires_the_system_view_permission(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/diagnostics')
            ->assertForbidden();

        $this->actingAs(User::factory()->role(Role::Support)->create())
            ->get('/diagnostics')
            ->assertOk();
    }
}

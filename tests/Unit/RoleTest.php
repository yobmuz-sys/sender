<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Users\Enums\Role;
use App\Domain\Users\Permission;
use PHPUnit\Framework\TestCase;

class RoleTest extends TestCase
{
    public function test_super_admin_holds_every_permission(): void
    {
        foreach (Permission::all() as $permission) {
            $this->assertTrue(
                Role::SuperAdmin->allows($permission),
                "Super admin is missing {$permission}",
            );
        }
    }

    public function test_only_super_admin_can_manage_the_system(): void
    {
        $this->assertTrue(Role::SuperAdmin->allows(Permission::SYSTEM_MANAGE));

        foreach ([Role::Admin, Role::Support, Role::Operations, Role::Billing, Role::User] as $role) {
            $this->assertFalse($role->allows(Permission::SYSTEM_MANAGE), $role->value.' must not manage the system');
        }
    }

    public function test_a_plain_user_holds_no_administrative_permission(): void
    {
        $this->assertSame([], Role::User->permissions());
        $this->assertFalse(Role::User->isStaff());
    }

    public function test_every_role_only_receives_declared_permissions(): void
    {
        $known = Permission::all();

        foreach (Role::cases() as $role) {
            foreach ($role->permissions() as $permission) {
                $this->assertContains($permission, $known, "{$role->value} references an unknown permission");
            }
        }
    }

    public function test_staff_roles_are_recognised(): void
    {
        $staff = array_filter(Role::cases(), static fn (Role $role): bool => $role->isStaff());

        $this->assertSame(
            [Role::SuperAdmin, Role::Admin, Role::Support, Role::Operations, Role::Billing],
            array_values($staff),
        );
    }
}

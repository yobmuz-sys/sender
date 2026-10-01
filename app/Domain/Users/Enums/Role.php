<?php

declare(strict_types=1);

namespace App\Domain\Users\Enums;

use App\Domain\Users\Permission;

/**
 * Platform roles and the administrative permissions each one carries.
 *
 * Roles are intentionally coarse. Granular, per-account grants arrive with the
 * plans and entitlements stage, where they can be stored rather than implied.
 */
enum Role: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Support = 'support';
    case Operations = 'operations';
    case Billing = 'billing';
    case User = 'user';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Administrator',
            self::Admin => 'Administrator',
            self::Support => 'Support',
            self::Operations => 'Operations',
            self::Billing => 'Billing',
            self::User => 'User',
        };
    }

    /**
     * Whether this role can reach the administration area at all.
     */
    public function isStaff(): bool
    {
        return $this->permissions() !== [];
    }

    /**
     * The administrative permissions granted by this role.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::SuperAdmin => Permission::all(),

            // Full operational control, but not the ability to change the
            // platform's own configuration.
            self::Admin => array_values(array_diff(Permission::all(), [
                Permission::SYSTEM_MANAGE,
            ])),

            // Read-only across the surface, able to help users and investigate.
            self::Support => [
                Permission::USERS_VIEW,
                Permission::CAMPAIGNS_VIEW,
                Permission::JOBS_VIEW,
                Permission::SYSTEM_VIEW,
            ],

            self::Operations => [
                Permission::CAMPAIGNS_VIEW,
                Permission::CAMPAIGNS_PAUSE,
                Permission::JOBS_VIEW,
                Permission::JOBS_MANAGE,
                Permission::SYSTEM_VIEW,
            ],

            self::Billing => [
                Permission::USERS_VIEW,
                Permission::FEATURES_VIEW,
                Permission::PLANS_VIEW,
                Permission::PLANS_MANAGE,
            ],

            // Product entitlements for regular accounts are resolved from the
            // user's plan in a later stage, never from this role.
            self::User => [],
        };
    }

    public function allows(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}

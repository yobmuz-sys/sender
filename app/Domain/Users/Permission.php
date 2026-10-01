<?php

declare(strict_types=1);

namespace App\Domain\Users;

/**
 * The complete catalogue of administrative permissions.
 *
 * This class is the single source of truth for authorization. Gates are
 * registered from it at boot, so a permission can never exist in a template
 * while being absent from the gate layer (which would make `$user->can()`
 * return false for an unknown ability).
 */
final class Permission
{
    public const ADMIN_VIEW = 'admin.view';

    public const USERS_VIEW = 'users.view';

    public const USERS_CREATE = 'users.create';

    public const USERS_EDIT = 'users.edit';

    public const USERS_SUSPEND = 'users.suspend';

    public const FEATURES_VIEW = 'features.view';

    public const FEATURES_MANAGE = 'features.manage';

    public const PLANS_VIEW = 'plans.view';

    public const PLANS_MANAGE = 'plans.manage';

    public const CAMPAIGNS_VIEW = 'campaigns.view';

    public const CAMPAIGNS_PAUSE = 'campaigns.pause';

    public const JOBS_VIEW = 'jobs.view';

    public const JOBS_MANAGE = 'jobs.manage';

    public const SYSTEM_VIEW = 'system.view';

    public const SYSTEM_MANAGE = 'system.manage';

    /**
     * Every permission known to the application.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        // groups() returns a list of lists keyed by area; flatten it so callers
        // receive a plain list of permission strings.
        return array_values(array_merge(...array_values(self::groups())));
    }

    /**
     * Permissions grouped by the area they protect, for the administration UI.
     *
     * @return array<string, list<string>>
     */
    public static function groups(): array
    {
        return [
            'Administration' => [
                self::ADMIN_VIEW,
            ],
            'Users' => [
                self::USERS_VIEW,
                self::USERS_CREATE,
                self::USERS_EDIT,
                self::USERS_SUSPEND,
            ],
            'Features' => [
                self::FEATURES_VIEW,
                self::FEATURES_MANAGE,
            ],
            'Plans' => [
                self::PLANS_VIEW,
                self::PLANS_MANAGE,
            ],
            'Campaigns' => [
                self::CAMPAIGNS_VIEW,
                self::CAMPAIGNS_PAUSE,
            ],
            'Jobs' => [
                self::JOBS_VIEW,
                self::JOBS_MANAGE,
            ],
            'System' => [
                self::SYSTEM_VIEW,
                self::SYSTEM_MANAGE,
            ],
        ];
    }
}

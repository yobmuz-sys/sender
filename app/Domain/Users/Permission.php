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

    /**
     * Read transport metadata across tenants.
     *
     * Deliberately separate from `mail_accounts.manage`: a support role needs to
     * answer "is this customer's mail working", and must not thereby gain the
     * ability to create, reassign or disable an operator's transport.
     */
    public const MAIL_ACCOUNTS_VIEW = 'mail_accounts.view';

    /**
     * Create, edit, disable and verify any tenant's SMTP account.
     */
    public const MAIL_ACCOUNTS_MANAGE = 'mail_accounts.manage';

    /**
     * Assign a transport to a tenant.
     *
     * Split from `manage` because the assignment decides whose address a tenant's
     * mail appears to come from — a change with consequences beyond the transport
     * itself, and one an operator should take deliberately.
     */
    public const MAIL_ACCOUNTS_ASSIGN = 'mail_accounts.assign';

    /**
     * Read deliverability findings across tenants.
     *
     * Findings contain DNS evidence and configuration metadata. They never
     * contain a credential, so this permission carries no disclosure risk of its
     * own — which is the reason it is not part of the mail account permissions.
     */
    public const DELIVERABILITY_VIEW = 'deliverability.view';

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
            'Mail' => [
                self::MAIL_ACCOUNTS_VIEW,
                self::MAIL_ACCOUNTS_MANAGE,
                self::MAIL_ACCOUNTS_ASSIGN,
                self::DELIVERABILITY_VIEW,
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

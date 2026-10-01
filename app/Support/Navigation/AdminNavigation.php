<?php

declare(strict_types=1);

namespace App\Support\Navigation;

use App\Domain\Users\Permission;

/**
 * The administrative surface, declared in one place.
 *
 * Every entry names a real route and the permission that reaches it. Nothing
 * here decides access — that stays with the Gate layer, registered from
 * {@see Permission} — so this is a description of the surface, not a second
 * authorization mechanism.
 */
final class AdminNavigation
{
    /**
     * @return list<NavigationItem>
     */
    public static function items(): array
    {
        return [
            new NavigationItem(
                'Overview',
                'admin.dashboard',
                // Reachable by any role carrying at least one permission.
                // Role::isStaff() is defined as "has permissions", so this is a
                // projection of the catalogue rather than a separate staff list.
                permission: null,
                matchPrefix: false,
            ),
            new NavigationItem('Users', 'admin.users.index', Permission::USERS_VIEW, matchPrefix: true),
            new NavigationItem('Roles & permissions', 'admin.roles.index', Permission::USERS_VIEW),
            new NavigationItem('Features', 'admin.features.index', Permission::FEATURES_VIEW),
            new NavigationItem('Plans', 'admin.plans.index', Permission::PLANS_VIEW),
            new NavigationItem('Campaigns', 'admin.campaigns.index', Permission::CAMPAIGNS_VIEW),
            new NavigationItem('Jobs', 'admin.jobs.index', Permission::JOBS_VIEW),
            new NavigationItem('Runs', 'admin.runs.index', Permission::JOBS_VIEW),
            new NavigationItem('SMTP', 'admin.smtp.index', Permission::SYSTEM_VIEW),
            new NavigationItem('System', 'admin.system.index', Permission::SYSTEM_VIEW, matchPrefix: true, children: [
                new NavigationItem('Overview', 'admin.system.index', Permission::SYSTEM_VIEW),
                new NavigationItem('Diagnostics', 'admin.system.diagnostics', Permission::SYSTEM_VIEW),
                new NavigationItem('Subsystems', 'admin.system.subsystems', Permission::SYSTEM_VIEW),
                new NavigationItem('Settings', 'admin.settings.index', Permission::SYSTEM_VIEW),
                new NavigationItem('Audit log', 'admin.audit.index', Permission::SYSTEM_VIEW),
            ]),
            new NavigationItem('API', 'admin.api.index', Permission::SYSTEM_VIEW),
            new NavigationItem('Billing', 'admin.billing.index', Permission::SYSTEM_VIEW),
        ];
    }

    /**
     * Every route name the administration surface references.
     *
     * @return list<string>
     */
    public static function routeNames(): array
    {
        $names = [];

        $collect = static function (array $items) use (&$collect, &$names): void {
            foreach ($items as $item) {
                $names[] = $item->route;

                if ($item->children !== []) {
                    $collect($item->children);
                }
            }
        };

        $collect(self::items());

        return array_values(array_unique($names));
    }
}

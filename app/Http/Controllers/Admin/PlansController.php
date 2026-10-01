<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Users\Permission;

final class PlansController extends StagedPageController
{
    protected function title(): string
    {
        return 'Plans';
    }

    protected function description(): string
    {
        return 'Plans describe what an account may use and how much of it. No plan table '
            .'exists yet, so nothing here can be created, priced or assigned without '
            .'inventing data that a later stage would have to migrate.';
    }

    protected function stage(): string
    {
        return 'Stage 3E — plans and entitlements';
    }

    protected function permission(): string
    {
        return Permission::PLANS_VIEW;
    }

    /**
     * @return array<string, string>
     */
    protected function plannedCapabilities(): array
    {
        return [
            'Plan records with quotas',
            'Entitlement resolution in place of the deny-by-default stub',
            'Billing integration',
        ];
    }
}

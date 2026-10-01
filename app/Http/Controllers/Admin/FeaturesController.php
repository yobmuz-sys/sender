<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Users\Permission;

final class FeaturesController extends StagedPageController
{
    protected function title(): string
    {
        return 'Features';
    }

    protected function description(): string
    {
        return 'The feature catalogue is what turns an installed platform into a product. '
            .'It arrives with the plans and entitlement domain, which replaces the '
            .'deny-by-default stub that is in force today.';
    }

    protected function stage(): string
    {
        return 'Stage 3E — feature and entitlement domain';
    }

    protected function permission(): string
    {
        return Permission::FEATURES_VIEW;
    }

    /**
     * @return array<string, string>
     */
    protected function plannedCapabilities(): array
    {
        return [
            'Feature catalogue with descriptions',
            'Per-account and per-plan entitlement resolution',
            'Plan limits expressed as entitlements',
        ];
    }
}

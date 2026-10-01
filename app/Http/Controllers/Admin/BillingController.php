<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Users\Permission;

final class BillingController extends StagedPageController
{
    protected function title(): string
    {
        return 'Billing';
    }

    protected function description(): string
    {
        return 'Invoicing, payment collection and plan changes. Billing depends on plans and '
            .'entitlements existing first, and guessing its shape before then would produce '
            .'records that have to be migrated.';
    }

    protected function stage(): string
    {
        return 'Stage 8 — billing';
    }

    protected function permission(): string
    {
        return Permission::SYSTEM_VIEW;
    }

    /**
     * @return array<string, string>
     */
    protected function plannedCapabilities(): array
    {
        return [
            'Customer and subscription records',
            'Payment provider integration',
            'Invoice generation',
        ];
    }
}

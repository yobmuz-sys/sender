<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Users\Permission;

final class CampaignsController extends StagedPageController
{
    protected function title(): string
    {
        return 'Campaigns';
    }

    protected function description(): string
    {
        return 'Campaigns combine an extracted list with an SMTP send. Neither half exists: '
            .'the extractor is Stage 4 and the sender is Stage 5, and a campaign cannot be '
            .'built on a list that has never been extracted.';
    }

    protected function stage(): string
    {
        return 'Stage 4 (extraction) then Stage 5 (SMTP delivery)';
    }

    protected function permission(): string
    {
        return Permission::CAMPAIGNS_VIEW;
    }

    /**
     * @return array<string, string>
     */
    protected function plannedCapabilities(): array
    {
        return [
            'Campaign records and lifecycle',
            'Recipient and suppression handling',
            'Send progress and delivery reporting',
        ];
    }
}

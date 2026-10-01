<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Users\Permission;

final class AuditController extends StagedPageController
{
    protected function title(): string
    {
        return 'Audit log';
    }

    protected function description(): string
    {
        return 'A record of who changed what, and when. It arrives with the domains it '
            .'describes rather than ahead of them, because an audit log built over a '
            .'half-finished schema records the wrong events and has to be redone.';
    }

    protected function stage(): string
    {
        return 'Stage 3C — operational domains';
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
            'Immutable operator action records',
            'Per-entity change history',
            'Retention policy',
        ];
    }
}

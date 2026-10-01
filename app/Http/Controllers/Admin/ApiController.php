<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Users\Permission;

final class ApiController extends StagedPageController
{
    protected function title(): string
    {
        return 'API';
    }

    protected function description(): string
    {
        return 'The public integration surface. It is intentionally last among the platform '
            .'features: an API over an unstable domain would need rebuilding with every '
            .'stage, and the PHP integration customers actually use comes first.';
    }

    protected function stage(): string
    {
        return 'Stage 7 — REST API and PHP integration';
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
            'Token issuance and revocation',
            'Versioned endpoints over extraction and campaigns',
            'Rate limiting and usage accounting',
        ];
    }
}

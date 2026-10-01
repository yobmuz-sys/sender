<?php

declare(strict_types=1);

namespace App\Domain\System\Contracts;

use App\Domain\System\HostCapabilityReport;

/**
 * Measures the host this application is running on.
 *
 * The capability registry depends on this contract rather than on the
 * concrete inspector, because the registry's job is to interpret a report and
 * not to know how the report was produced.
 */
interface HostInspector
{
    public function inspect(): HostCapabilityReport;
}

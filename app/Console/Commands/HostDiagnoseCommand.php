<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\System\Services\HostCapabilityInspector;
use Illuminate\Console\Command;

class HostDiagnoseCommand extends Command
{
    protected $signature = 'sender:diagnose';

    protected $description = 'Report whether this host can run the Sender platform';

    public function handle(HostCapabilityInspector $inspector): int
    {
        $report = $inspector->inspect();

        $rows = array_map(
            static fn ($check): array => [
                $check->name,
                $check->capability->label(),
                $check->detail,
            ],
            $report->checks,
        );

        $this->table(['Capability', 'Status', 'Detail'], $rows);

        foreach ($report->problems() as $problem) {
            foreach ($problem->remedies as $remedy) {
                $this->warn($remedy);
            }
        }

        $this->newLine();
        $this->line('Overall: '.$report->overall->label());

        return $report->passes ? self::SUCCESS : self::FAILURE;
    }
}

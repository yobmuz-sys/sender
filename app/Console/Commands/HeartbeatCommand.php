<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\System\Capabilities\CapabilityRegistry;
use App\Domain\System\Enums\CapabilitySubject;
use App\Domain\System\Services\CronHeartbeat;
use Illuminate\Console\Command;

/**
 * Records that the host's scheduler ran the platform.
 *
 * cPanel offers no way to ask PHP "is cron configured?". The only honest
 * signal is that this command actually executed, so the cron capability stays
 * UNKNOWN until an operator points a Cron Job at it.
 *
 * Stage 2 observes cron only. Actual job processing arrives in Stage 3, and
 * this command will be the first thing that cron runs then.
 */
class HeartbeatCommand extends Command
{
    protected $signature = 'sender:heartbeat';

    protected $description = 'Record that the platform scheduler ran (point your cPanel Cron Job here)';

    public function handle(CronHeartbeat $heartbeat): int
    {
        $heartbeat->record();

        $this->info('Heartbeat recorded.');

        $this->line('cron capability: '.app(CapabilityRegistry::class)
            ->status(CapabilitySubject::Cron)->label());

        return self::SUCCESS;
    }
}

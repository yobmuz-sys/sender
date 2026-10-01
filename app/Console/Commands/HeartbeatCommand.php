<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\System\Capabilities\CapabilityRegistry;
use App\Domain\System\Enums\CapabilitySubject;
use App\Domain\System\Runs\RecordsScheduledRun;
use App\Domain\System\Runs\RunRecorder;
use Illuminate\Console\Command;
use Throwable;

/**
 * The command a cPanel Cron Job points at.
 *
 * cPanel cannot be detected from inside PHP: there is no API that answers "is a
 * cron entry configured?". The only honest signal is that this command actually
 * executed, so it exists to leave durable evidence of having run.
 *
 * Its own outcome is recorded too. An earlier design wrote only a cache-backed
 * timestamp, which could not distinguish "cron ran" from "cron ran and failed",
 * and could be erased by `cache:clear`. The recorded run answers the question an
 * operator actually has.
 */
class HeartbeatCommand extends Command
{
    use RecordsScheduledRun;

    protected $signature = 'sender:heartbeat';

    protected $description = 'Record that the platform scheduler ran (point your cPanel Cron Job here)';

    public function handle(RunRecorder $recorder): int
    {
        $this->recordRun($recorder);

        try {
            $this->completeRun();

            $this->info('Run recorded.');

            $this->line('cron capability: '.app(CapabilityRegistry::class)
                ->status(CapabilitySubject::Cron)->label());
        } catch (Throwable $exception) {
            $this->failRun($exception);

            throw $exception;
        }

        return self::SUCCESS;
    }
}

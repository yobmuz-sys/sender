<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\System\Capabilities\AvailabilityResolver;
use App\Domain\System\Capabilities\CapabilityRegistry;
use App\Domain\System\Enums\CapabilityStatus;
use App\Domain\System\Enums\CapabilitySubject;
use App\Domain\System\Enums\Subsystem;
use Illuminate\Console\Command;

class HostDiagnoseCommand extends Command
{
    protected $signature = 'sender:diagnose';

    protected $description = 'Report whether this host can run the Sender platform';

    public function handle(CapabilityRegistry $capabilities, AvailabilityResolver $availability): int
    {
        foreach ($capabilities->report()->checks as $check) {
            $this->line(sprintf(
                '%-26s %-12s %s',
                $check->name,
                $check->capability->label(),
                $check->detail,
            ));
        }

        $this->newLine();
        $this->line('Capabilities');
        $this->line('-----------');

        foreach (CapabilitySubject::cases() as $subject) {
            $status = $capabilities->status($subject);

            $this->line(sprintf(
                '%-26s %-12s %s',
                $subject->value,
                $status->label(),
                $subject->isRequired() ? '(required)' : '',
            ));
        }

        $this->newLine();
        $this->line('Subsystems');
        $this->line('----------');

        foreach (Subsystem::cases() as $subsystem) {
            $result = $availability->resolve($subsystem);

            $this->line(sprintf(
                '%-26s %-12s %s',
                $subsystem->value,
                $result->state->label(),
                $result->reason?->value ?? '',
            ));
        }

        $this->newLine();

        foreach ($capabilities->problems() as $problem) {
            foreach ($problem->remedies as $remedy) {
                $this->warn($remedy);
            }
        }

        $overall = $capabilities->overall();

        $this->line('Overall: '.$overall->label());

        return $this->exitCodeFor($overall);
    }

    /**
     * Diagnostic severity and process exit status are not the same thing.
     *
     * A degraded host is operational: it works with less headroom, and the
     * deployment limits exist precisely so it stays inside that headroom.
     * Failing the command for DEGRADED would train operators to ignore it, and
     * would make the check useless on the small shared plans the platform
     * targets.
     *
     * Only a genuinely missing dependency, or a required capability that has
     * never been established, is a failure. Note that CapabilityRegistry only
     * lets an UNKNOWN status reach the overall verdict when the subject is
     * required, so UNKNOWN here always means "a required capability is
     * unestablished".
     */
    private function exitCodeFor(CapabilityStatus $overall): int
    {
        return match ($overall) {
            CapabilityStatus::Unavailable, CapabilityStatus::Unknown => self::FAILURE,
            CapabilityStatus::Ready, CapabilityStatus::Degraded => self::SUCCESS,
        };
    }
}

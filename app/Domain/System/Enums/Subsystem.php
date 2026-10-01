<?php

declare(strict_types=1);

namespace App\Domain\System\Enums;

/**
 * Operator-controlled subsystems that can be stopped independently.
 *
 * A subsystem flag answers "has the operator enabled this?", which is a
 * different question from "can the infrastructure support it?". Safe mode,
 * emergency disablement and module toggles are all this one flag, not three
 * systems.
 *
 * Only subsystems that exist today are listed. Flags are added when a
 * subsystem is added, never speculatively.
 */
enum Subsystem: string
{
    case Cron = 'cron';
    case UrlFetch = 'url_fetch';
    case Smtp = 'smtp';

    public function label(): string
    {
        return match ($this) {
            self::Cron => 'Scheduled processing',
            self::UrlFetch => 'URL fetching',
            self::Smtp => 'SMTP delivery',
        };
    }

    /**
     * The infrastructure capability this subsystem depends on.
     */
    public function subject(): CapabilitySubject
    {
        return CapabilitySubject::from($this->value);
    }
}

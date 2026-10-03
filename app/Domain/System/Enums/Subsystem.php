<?php

declare(strict_types=1);

namespace App\Domain\System\Enums;

use App\Domain\Audience\SmtpProbingPolicy;

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
    case SmtpValidation = 'smtp_validation';

    public function label(): string
    {
        return match ($this) {
            self::Cron => 'Scheduled processing',
            self::UrlFetch => 'URL fetching',
            self::Smtp => 'SMTP delivery',
            self::SmtpValidation => 'Recipient SMTP validation',
        };
    }

    /**
     * The infrastructure capability this subsystem depends on, if it has one.
     *
     * A null subject is not a missing measurement. Recipient validation probing
     * is the one subsystem the platform deliberately never measures: establishing
     * it would mean dialling a mail server on port 25 from the operator's request,
     * which is exactly the behaviour the flag exists to prevent. Reporting
     * UNKNOWN for it would be the dishonest reading — it would say "nobody has
     * checked" when the truth is "checking is the thing being controlled" — so
     * the subsystem simply has no capability dimension and is decided by the
     * operator flag and the deployment ceiling alone.
     *
     * @see SmtpProbingPolicy
     */
    public function subject(): ?CapabilitySubject
    {
        return match ($this) {
            self::SmtpValidation => null,
            default => CapabilitySubject::from($this->value),
        };
    }

    /**
     * Whether the deployment permits this subsystem at all.
     *
     * A hard ceiling is one input among several, and the only one the platform
     * does not offer an operator a way around: no amount of enabling in the
     * browser opens a port the host has closed.
     */
    public function deploymentAllows(): bool
    {
        return match ($this) {
            self::SmtpValidation => (bool) config('sender.validation.smtp_probing', false),
            default => true,
        };
    }
}

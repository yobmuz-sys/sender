<?php

declare(strict_types=1);

namespace App\Domain\Audience;

use App\Domain\System\Enums\Subsystem;
use App\Domain\System\Flags\SubsystemFlagRegistry;

/**
 * Whether the platform may open an SMTP conversation with a recipient's server.
 *
 * Two inputs, and deliberately no third:
 *
 *   1. the deployment ceiling, SENDER_VALIDATION_SMTP_PROBING, which is the only
 *      control the platform does not let an operator work around;
 *   2. the operator's persisted switch, which exists so an incident can stop
 *      outbound port 25 without a deploy.
 *
 * Entitlement is not an input. Nothing in this class asks whether an account is
 * entitled to recipient validation, and that is intentional: the switch is a
 * safety control over the host's outbound traffic, not a commercial decision.
 * A tenant who may not have validation must still not be able to make the
 * server dial strangers.
 *
 * The two inputs compose as an AND, never as a preference. An operator enabling
 * a subsystem the deployment forbids is refused rather than honoured, because
 * the reason the ceiling exists is that the port is closed — and the platform
 * cannot distinguish "the operator made a mistake" from "the operator wants the
 * platform to try anyway" from inside the application.
 */
final class SmtpProbingPolicy
{
    public function __construct(
        private readonly SubsystemFlagRegistry $flags,
        private readonly bool $permittedByEnvironment,
    ) {}

    public static function fromEnvironment(SubsystemFlagRegistry $flags): self
    {
        return new self($flags, (bool) config('sender.validation.smtp_probing', false));
    }

    /**
     * Whether a recipient probe may be attempted now.
     */
    public function enabled(): bool
    {
        return $this->permittedByEnvironment
            && $this->flags->enabled(Subsystem::SmtpValidation);
    }

    /**
     * Whether the deployment permits probing at all, regardless of the operator.
     *
     * Reported separately so an operator who enabled the switch on a host with
     * port 25 blocked is told that the environment, not their click, is the
     * reason nothing is happening.
     */
    public function permittedByEnvironment(): bool
    {
        return $this->permittedByEnvironment;
    }

    /**
     * Why probing is off, in the vocabulary the rest of the platform uses.
     *
     * A caller that is about to record UNKNOWN uses this to distinguish "the
     * operator stopped us" from "this host never allowed it", because a tenant
     * shown the first can ask for it back and a tenant shown the second cannot.
     */
    public function blockedBecause(): ?string
    {
        if ($this->enabled()) {
            return null;
        }

        return $this->flags->enabled(Subsystem::SmtpValidation)
            ? 'the deployment does not permit recipient probing'
            : 'an operator has disabled recipient probing';
    }
}

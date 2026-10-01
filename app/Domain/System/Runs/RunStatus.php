<?php

declare(strict_types=1);

namespace App\Domain\System\Runs;

/**
 * How a scheduled run ended.
 *
 * `Running` is recorded so an invocation that is killed mid-flight — a
 * timeout, or a host that terminates a long cron process — leaves evidence
 * rather than disappearing. A run still marked Running is an operator signal,
 * not a healthy state.
 */
enum RunStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Running',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
        };
    }
}

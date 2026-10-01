<?php

declare(strict_types=1);

namespace App\Domain\System\Enums;

/**
 * The things the platform can measure about its environment.
 *
 * A subject names what is being asked about; the answer is a
 * {@see CapabilityStatus}. Keeping the two apart is what lets a feature ask
 * "is URL fetching available?" without the enum also having to describe what
 * "unavailable" means.
 *
 * Subjects are added only when something establishes them. A subject nobody
 * measures reports UNKNOWN, which is honest, rather than READY.
 */
enum CapabilitySubject: string
{
    case Cron = 'cron';
    case UrlFetch = 'url_fetch';
    case Smtp = 'smtp';
    case Queue = 'queue';
    case Storage = 'storage';

    public function label(): string
    {
        return match ($this) {
            self::Cron => 'Scheduled processing',
            self::UrlFetch => 'Outbound URL fetching',
            self::Smtp => 'SMTP delivery',
            self::Queue => 'Database queue',
            self::Storage => 'Filesystem storage',
        };
    }

    /**
     * Whether the platform must be able to establish this capability.
     *
     * Configured in sender.capabilities.required. A required subject that is
     * UNKNOWN is a failure, because something depends on it. A non-required
     * subject that is UNKNOWN is a note, because nothing does yet.
     */
    public function isRequired(): bool
    {
        return in_array($this->value, (array) config('sender.capabilities.required', []), true);
    }
}

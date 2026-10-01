<?php

declare(strict_types=1);

namespace App\Domain\System\Services;

use App\Domain\System\CapabilityCheck;
use App\Domain\System\Enums\CapabilitySubject;
use Illuminate\Support\Facades\Cache;

/**
 * Records and interprets the last observed cron execution.
 *
 * cPanel cron cannot be detected from inside PHP: there is no API that answers
 * "is a cron entry configured?". The only honest signal is that the scheduled
 * command actually ran, so cron is UNKNOWN until a heartbeat is observed.
 *
 * Storage is the existing cache rather than a dedicated table. Clearing the
 * cache therefore resets cron to UNKNOWN, which is the safe direction to
 * fail: the platform reports "not established" instead of falsely claiming
 * that automation is running.
 */
final class CronHeartbeat
{
    private const CACHE_KEY = 'sender:capability:cron:last_seen';

    /**
     * Record that the scheduled command ran. Safe to invoke repeatedly.
     */
    public function record(): void
    {
        Cache::forever(self::CACHE_KEY, now()->getTimestamp());
    }

    /**
     * The last observed execution, or null if cron has never been seen.
     */
    public function lastSeen(): ?int
    {
        $value = Cache::get(self::CACHE_KEY);

        return is_int($value) ? $value : null;
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * How long a heartbeat stays acceptable.
     */
    public function staleAfterSeconds(): int
    {
        return (int) config('sender.capabilities.cron.stale_after_seconds', 900);
    }

    /**
     * Turn the observed heartbeat into a capability check.
     *
     * UNAVAILABLE is never produced here: there is no positive evidence
     * available from inside PHP that cron is switched off at the host level,
     * and inferring one would be a guess.
     */
    public function check(): CapabilityCheck
    {
        $lastSeen = $this->lastSeen();

        if ($lastSeen === null) {
            return CapabilityCheck::unknown(
                'cron scheduler',
                'no heartbeat observed since installation',
                [
                    'Cron has never run. Configure a cPanel Cron Job to call: php artisan sender:heartbeat',
                    'Background processing is not yet implemented; cron is only being observed at this stage.',
                ],
                CapabilitySubject::Cron,
            );
        }

        $age = max(0, now()->getTimestamp() - $lastSeen);

        if ($age > $this->staleAfterSeconds()) {
            return CapabilityCheck::degraded(
                'cron scheduler',
                sprintf('last heartbeat %ds ago (stale after %ds)', $age, $this->staleAfterSeconds()),
                ['Check that the cPanel Cron Job is still scheduled and has not started failing.'],
                CapabilitySubject::Cron,
            );
        }

        return CapabilityCheck::ready(
            'cron scheduler',
            sprintf('last heartbeat %ds ago', $age),
            subject: CapabilitySubject::Cron,
        );
    }
}

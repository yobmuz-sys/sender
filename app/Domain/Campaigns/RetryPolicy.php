<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

/**
 * When to try again, and when to stop trying.
 *
 * Bounded on every axis, because the unbounded version of this class is how a
 * sender ends up retrying a dead address for a week. Two ceilings, both hard:
 *
 *   - **a maximum number of attempts per recipient**, so one address cannot occupy
 *     the worker;
 *   - **a maximum delay between attempts**, so "come back later" cannot quietly
 *     become "next month".
 *
 * The growth between attempts is exponential, which is the point: a provider
 * issuing a `4xx` is usually saying the volume is too high, and retrying at the
 * same pace a few seconds later is how a throttle turns into a block. Each wait is
 * longer than the last because the evidence says to slow down, not because a fixed
 * schedule would be less forgiving.
 */
class RetryPolicy
{
    /**
     * Attempts allowed per recipient, including the first.
     */
    public function maxAttempts(): int
    {
        return max(1, (int) config('sender.campaigns.max_attempts', 4));
    }

    /**
     * How long to wait after the given attempt number failed temporarily.
     */
    public function delayAfterAttempt(int $attemptNumber): int
    {
        $base = max(1, (int) config('sender.campaigns.retry_base_seconds', 300));
        $ceiling = max($base, (int) config('sender.campaigns.retry_max_seconds', 3600));

        // attempt 1 failed -> wait `base`; attempt 2 -> `base * 2`, and so on. The
        // shift is clamped by the ceiling below before it is applied, so a long
        // campaign cannot overflow into a nonsensical delay.
        $shift = max(0, min($attemptNumber - 1, 10));

        return (int) min($ceiling, $base * (2 ** $shift));
    }

    /**
     * Whether an outcome is worth another attempt, and how long to wait first.
     *
     * Returns null when there should be no further attempt — which is the answer
     * for everything that is not a temporary failure, including a transport that
     * has stopped working. Retrying a refused credential is pressing on against a
     * provider that has already said no.
     */
    public function delayFor(AttemptResult $result, int $attemptsSoFar): ?int
    {
        if (! $result->isRetryable()) {
            return null;
        }

        if ($attemptsSoFar >= $this->maxAttempts()) {
            return null;
        }

        return $this->delayAfterAttempt($attemptsSoFar + 1);
    }
}

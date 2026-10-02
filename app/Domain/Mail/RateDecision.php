<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * What a rate policy decided for one submission.
 *
 * Modelled now, at Stage 5A, because getting the shape right is cheaper before
 * there is a queue to drain. The interesting decisions are not "how many per
 * second" but "what does a 4xx mean" — and those are consequences of a delivery
 * outcome, which is why {@see DeliveryOutcome} exists as a first-class input.
 */
final readonly class RateDecision
{
    /**
     * @param  int  $delaySeconds  How long to wait before this recipient's next
     *                             attempt. Zero means proceed now.
     */
    private function __construct(
        public bool $maySend,
        public int $delaySeconds,
        public string $reason,
    ) {}

    public static function send(): self
    {
        return new self(true, 0, 'within the configured limit');
    }

    /**
     * A temporary failure: hold off and try again, having learned something.
     */
    public static function backoff(int $delaySeconds, string $reason): self
    {
        return new self(false, max(1, $delaySeconds), $reason);
    }

    /**
     * Stop sending entirely for this transport.
     *
     * Used when credentials are rejected, and when a provider keeps returning
     * throttles. A deliberate stop, not a deferral: continuing would mean
     * pressing on against a provider that has already said no.
     */
    public static function pause(string $reason): self
    {
        return new self(false, 0, $reason);
    }

    /**
     * This recipient is permanently unreachable; do not attempt again.
     *
     * Distinguishing this from {@see backoff()} is what stops a campaign retrying
     * an address the server has already declared dead.
     */
    public static function recipientFailed(string $reason): self
    {
        return new self(false, 0, $reason);
    }

    /**
     * The full scope of a policy decision.
     */
    public function scope(): string
    {
        return $this->delaySeconds === 0 ? ($this->maySend ? 'send' : 'stop') : 'defer';
    }
}

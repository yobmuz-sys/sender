<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * The sending-rate contract Stage 5C will implement.
 *
 * Declared in Stage 5A with no implementation on purpose. Writing the
 * implementation first, before consent and suppression exist, would produce a
 * controller that paces mail to a list the platform cannot justify sending to —
 * and a pacing mechanism that looks finished is the sort of thing a later stage
 * builds on without revisiting.
 *
 * The interface exists so the *shape* is settled now, and specifically so two
 * behaviours are impossible to add later by accident:
 *
 *   1. It is outcome-aware. A policy that only counts messages cannot react to a
 *      4xx throttle, and a policy that reacts by switching transports is the
 *      rotation this platform refuses to build. {@see RateDecision::pause()} is
 *      therefore a first-class result — the honest answer to repeated rejection
 *      is to stop and let a person choose a different relay.
 *   2. It is bounded on every axis. Per account, per tenant, per campaign and
 *      platform-wide, with the platform limit not exceedable by a single tenant's
 *      volume.
 *
 * It is explicitly *not* a reputation-warming mechanism. Gradual sending is a
 * safety limit that protects recipients from a misconfigured transport; it is not
 * a way to raise an address's standing with a provider that has judged it.
 */
interface SendingRatePolicy
{
    /**
     * May one message be submitted now?
     *
     * @param  SmtpAccount  $account  The transport it will be submitted through.
     * @param  int|null  $campaignId  Null for a one-off message such as a
     *                                verification or test send.
     * @param  string|null  $recipientDomain  The recipient's domain, where the
     *                                        provider offers per-destination limits.
     */
    public function maySendNow(
        SmtpAccount $account,
        ?int $campaignId = null,
        ?string $recipientDomain = null,
    ): RateDecision;

    /**
     * Record what the receiving server actually said, and update the policy.
     *
     * This is the half that makes the policy more than a throttle. It is called
     * for every submission so that the decision to slow, pause or stop is made
     * from observed responses rather than from a static schedule.
     */
    public function record(SmtpAccount $account, DeliveryOutcome $outcome, ?int $campaignId = null): void;
}

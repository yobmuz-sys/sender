<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use App\Jobs\ProcessCampaignJob;
use App\Models\Contact;

/**
 * One pass of sending for one campaign, resumable from the database.
 *
 * The shape of this class is dictated entirely by where it runs: a cPanel cron
 * entry that starts a PHP process, does some work and is killed at the end of the
 * invocation. So:
 *
 *   - **No loop over the campaign.** The loop is bounded by a batch size the
 *     campaign configures and by a runtime budget this installation sets, and
 *     anything left over is left for the next invocation. A campaign of 50,000 is
 *     not 50,000 messages in one process, and any design that treats it that way
 *     dies at the host's execution limit with the campaign half done and no record
 *     of how far it got.
 *   - **No `sleep()`.** Pacing is `next_send_at` on the campaign and `next_attempt_at`
 *     on each recipient, both timestamps in the database. When the next send is in
 *     the future, the run ends and the queue is handed the next opportunity as a
 *     delayed job — which on shared hosting means the host scheduler decides the
 *     real cadence, and the page says so rather than implying a precision this
 *     installation does not have.
 *   - **Every claim is a conditional update.** The campaign is claimed, each
 *     recipient is claimed, and both are decided by the database rather than by a
 *     read followed by a write. Two workers cannot send the same message because
 *     there is no window in which both believe they own it.
 *
 * A worker killed mid-send leaves a recipient in `sending` with a claim nobody
 * holds. That is recovered rather than lost: the claim goes stale, and the next run
 * takes it again.
 */
class CampaignRunner
{
    /**
     * Seconds after which another worker may assume a claim was abandoned.
     *
     * Longer than any single submission can reasonably take — a send is bounded by
     * the transport's own timeout — so a live worker is never displaced.
     */
    private const CLAIM_STALE_AFTER_SECONDS = 900;

    /**
     * Wall-clock budget for one run, so a run cannot approach the host's own
     * execution limit and be killed mid-transaction.
     */
    private const RUN_BUDGET_SECONDS = 180;

    /**
     * Consecutive unconfirmed submissions that stop the campaign.
     *
     * An ambiguous outcome is never retried, so without this a broken transport
     * would walk the entire audience leaving every one of them `unknown` — a
     * campaign that contacted nobody and reported itself as finished, with an
     * unaccounted message sitting in a provider's queue for each.
     *
     * Three rather than one, for a reason that is about honesty rather than
     * caution: a single missing reply is genuinely indistinguishable from a
     * connection lost before the message was submitted, and halting a 50,000
     * recipient campaign on the strength of one blip would be its own kind of
     * false conclusion. Three in a row is not a blip, and the ones recorded before
     * the third are three recipients whose status is honestly "we do not know".
     */
    private const AMBIGUOUS_STREAK_LIMIT = 3;

    public function __construct(
        private readonly CampaignSender $sender,
        private readonly CampaignPreflight $preflight,
    ) {}

    /**
     * Send whatever this campaign may send right now.
     */
    public function run(Campaign $campaign): CampaignRunOutcome
    {
        // Anything not actively sending is somebody else's business: a paused
        // campaign must not be resumed by a worker, and a cancelled one must not
        // be resurrected by a job that was already queued when it was cancelled.
        if ($campaign->status !== CampaignStatus::Running
            && $campaign->status !== CampaignStatus::Scheduled) {
            return CampaignRunOutcome::skipped('This campaign is not sending.');
        }

        if ($campaign->status === CampaignStatus::Scheduled) {
            if ($campaign->scheduled_at !== null && $campaign->scheduled_at->isFuture()) {
                return $this->defer($campaign, $this->secondsUntil($campaign->scheduled_at));
            }

            // A campaign whose preflight stopped passing while it waited — a
            // transport that expired, a template that was emptied — stops here
            // rather than starting on the strength of a decision made yesterday.
            $report = $this->preflight->reportFor($campaign);

            if (! $report->canLaunch()) {
                $campaign->markFailed('The campaign stopped before it started: '.$report->summary());

                return CampaignRunOutcome::stopped('The campaign no longer passes its checks.');
            }

            $campaign->markRunning();
        }

        if (! $campaign->claimForWorker(self::CLAIM_STALE_AFTER_SECONDS)) {
            return CampaignRunOutcome::skipped('Another worker is already processing this campaign.');
        }

        try {
            return $this->sendBatch($campaign);
        } finally {
            $campaign->releaseWorkerClaim();
        }
    }

    /**
     * The sending loop, bounded on every axis.
     */
    private function sendBatch(Campaign $campaign): CampaignRunOutcome
    {
        $interval = $this->preflight->effectiveIntervalSeconds($campaign);
        $batch = $this->preflight->effectiveBatchSize($campaign);
        $deadline = now()->addSeconds(self::RUN_BUDGET_SECONDS);

        $sent = 0;
        $failed = 0;
        $deferred = 0;

        while ($sent < $batch && now()->lessThan($deadline)) {
            $waitSeconds = $this->secondsUntilNextSendIsAllowed($campaign, $interval);

            if ($waitSeconds > 0) {
                return $this->defer($campaign, $waitSeconds, $sent, $failed);
            }

            $recipient = $this->nextRecipient($campaign);

            if ($recipient === null) {
                return $this->settle($campaign, $sent, $failed);
            }

            if (! $recipient->claim(self::CLAIM_STALE_AFTER_SECONDS)) {
                // Another worker took it between our read and our write. Not an
                // error and not counted: it is that worker's send now.
                continue;
            }

            $contact = $recipient->contact;
            $outcome = $this->sender->send($campaign, $recipient);

            if ($outcome === AttemptResult::Accepted) {
                $sent++;
            } else {
                $failed++;
            }

            if ($outcome === AttemptResult::TransportFailure) {
                $campaign->markFailed($this->transportFailureReason($outcome, $contact));

                return CampaignRunOutcome::stopped(
                    'The transport stopped accepting mail, so the campaign was stopped rather than '
                        .'switched to another account.',
                    $sent,
                    $failed,
                );
            }

            if ($outcome === AttemptResult::Ambiguous) {
                /*
                 * Never retried — that recipient is already terminal — but counted,
                 * because nothing else bounds this.
                 *
                 * The streak is read from the recipient rows rather than a variable,
                 * and that is not a stylistic choice. Pacing means a run normally
                 * sends one message and defers, so three unconfirmed submissions are
                 * three worker invocations rather than three iterations of this loop,
                 * and an in-memory counter would reset to zero every time. Without
                 * this, a broken transport would walk the whole audience leaving every
                 * recipient unconfirmed: a campaign that contacted nobody and
                 * reported itself finished, with an unaccounted message in a queue
                 * for each.
                 */
                if ($this->unconfirmedStreak($campaign) >= self::AMBIGUOUS_STREAK_LIMIT) {
                    $campaign->markFailed(
                        'The sending transport stopped replying: '.self::AMBIGUOUS_STREAK_LIMIT
                            .' of the most recent submissions produced no result at all, so the campaign '
                            .'was stopped. Those messages may have been accepted by a server that never '
                            .'replied, which is why they are recorded as unconfirmed rather than sent again '
                            .'automatically — resending them could deliver the same message twice.',
                    );

                    return CampaignRunOutcome::stopped(
                        'The transport stopped replying, so the campaign was stopped.',
                        $sent,
                        $failed,
                    );
                }
            }

            $this->advancePace($campaign, $interval);
            $campaign->touchActivity();
        }

        $outcome = $this->settle($campaign, $sent, $failed);

        return $outcome;
    }

    /**
     * The next recipient this campaign may attempt, or null if none is due.
     */
    private function nextRecipient(Campaign $campaign): ?CampaignRecipient
    {
        return CampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->due(self::CLAIM_STALE_AFTER_SECONDS)
            ->orderBy('id')
            ->first();
    }

    /**
     * How many of this campaign's most recent submissions came back with no result.
     *
     * Read from the recipient rows rather than counted as the run proceeds, because a
     * run normally sends one message and defers — the three submissions that trip the
     * limit happen across three worker invocations, hours apart, and a counter held
     * in memory would forget the first two.
     *
     * Only recipients that were actually attempted are considered. A queued one has
     * a null `last_attempt_at` and says nothing about the transport.
     */
    private function unconfirmedStreak(Campaign $campaign): int
    {
        return CampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->whereNotNull('last_attempt_at')
            ->orderByDesc('last_attempt_at')
            ->orderByDesc('id')
            ->limit(self::AMBIGUOUS_STREAK_LIMIT)
            ->where('status', CampaignRecipientStatus::Unknown->value)
            ->count();
    }

    /**
     * Finish, re-schedule, or complete — whichever the campaign's state calls for.
     */
    private function settle(Campaign $campaign, int $sent, int $failed): CampaignRunOutcome
    {
        if ($campaign->hasPendingRecipients()) {
            return $this->defer($campaign, 0, $sent, $failed);
        }

        if ($campaign->recipients()->where('status', CampaignRecipientStatus::Queued->value)->exists()) {
            // Nothing is due yet, but work remains: come back when the earliest
            // retry is ready rather than sitting on a job until then.
            $earliest = CampaignRecipient::query()
                ->where('campaign_id', $campaign->id)
                ->where('status', CampaignRecipientStatus::Queued->value)
                ->min('next_attempt_at');

            $wait = $earliest !== null ? $this->secondsUntil($earliest) : 60;

            return $this->defer($campaign, $wait, $sent, $failed);
        }

        $campaign->complete();

        return CampaignRunOutcome::completed($sent, $failed);
    }

    /**
     * Hand the next opportunity to the queue rather than waiting for it.
     *
     * A delayed job, not a wait. If the host's scheduler never wakes up again the
     * work simply waits in the database, which is a visible stall rather than a
     * campaign that has quietly given up.
     */
    private function defer(Campaign $campaign, int $seconds, int $sent = 0, int $failed = 0): CampaignRunOutcome
    {
        ProcessCampaignJob::dispatch($campaign->id)->delay(max(0, $seconds));

        return CampaignRunOutcome::deferred($sent, $failed, max(0, $seconds));
    }

    /**
     * How long until this campaign may send again, in whole seconds.
     *
     * Computed from timestamps rather than `diffInSeconds()`, which rounds to the
     * nearest second: a clock set to "now" a fraction of a second ago would round
     * up to one second and make a campaign that should have started immediately wait
     * for a run it did not need. Truncation is the honest direction here — it can
     * only make a send happen a fraction early, never late.
     */
    private function secondsUntilNextSendIsAllowed(Campaign $campaign, int $interval): int
    {
        if ($campaign->next_send_at === null) {
            return 0;
        }

        return max(
            0,
            $campaign->next_send_at->getTimestamp() - now()->getTimestamp(),
        );
    }

    /**
     * Whole seconds between now and a moment in the future.
     */
    private function secondsUntil(\DateTimeInterface $moment): int
    {
        return max(0, $moment->getTimestamp() - now()->getTimestamp());
    }

    /**
     * Move the durable pace clock forward by one interval.
     *
     * The next send is measured from the last one, not from "now when the worker
     * happened to wake up", so the configured interval is the real spacing between
     * messages rather than a delay added to whatever gap the scheduler imposed.
     *
     * Written through the model rather than the query builder, even though this
     * worker holds the campaign's claim and could safely write directly. A direct
     * update leaves the model's dirty-tracking out of step with the row, and the
     * next save of that same model — a pause, a resume, an activity touch — then
     * omits fields it believes are unchanged. That has already cost one campaign a
     * stuck clock; the safe form is the one that keeps one writer.
     */
    private function advancePace(Campaign $campaign, int $interval): void
    {
        $next = ($campaign->next_send_at ?? now())->copy()->addSeconds($interval);

        $campaign->forceFill(['next_send_at' => $next])->save();
    }

    /**
     * Why a transport failure stopped the campaign, in words for the page.
     */
    private function transportFailureReason(AttemptResult $outcome, ?Contact $contact): string
    {
        return 'The sending transport failed while sending to '
            .($contact instanceof Contact ? $contact->email : 'a recipient')
            .'. This campaign was stopped rather than switched to another transport, because a '
            .'transport that is failing is a problem for a person to fix.';
    }
}

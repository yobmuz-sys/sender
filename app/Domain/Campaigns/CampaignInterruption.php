<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

/**
 * Why a campaign is not sending, and what a person can safely do about it.
 *
 * This exists because "Failed" and "Paused" are not the same situation and must
 * not read the same way. A pause is a decision somebody made and can undo; a
 * failure is the platform having stopped because continuing would be wrong. A
 * customer who sees one and is offered the other's remedy has been told something
 * false about their own campaign.
 *
 * Two rules shape every string here:
 *
 *   - **No remedy ever suggests sending through a different account.** A transport
 *     that is failing is a thing to fix, and quietly moving a campaign to another
 *     account would present a failing send as a working one. That is the whole
 *     reason {@see CampaignStatus::Failed} is separate from `Paused`, and advice
 *     that undid it here would undo it in the one place a customer is reading.
 *   - **The platform's own reason is quoted, not paraphrased.** A campaign carries
 *     the sentence written when it stopped, and that sentence names the transport
 *     and the decision. Summarising it would be this page inventing a reason it
 *     does not have.
 *
 * Null when nothing is wrong: a running, draft or completed campaign has no
 * interruption to explain, and a panel explaining nothing is noise.
 */
final readonly class CampaignInterruption
{
    private function __construct(
        public string $headline,
        public string $detail,
        public string $nextStep,
        public bool $recoverable,
    ) {}

    public static function for(Campaign $campaign): ?self
    {
        return match ($campaign->status) {
            CampaignStatus::Paused => new self(
                'Sending paused',
                'You stopped this campaign. Messages the server has already accepted are not recalled, and '
                    .'everyone still waiting is kept exactly as they were.',
                'Resume when you are ready, or cancel it to stop for good. Resuming resets the pace clock, so a '
                    .'campaign paused for a week does not send a week of backlog in the first minute.',
                true,
            ),

            CampaignStatus::Failed => new self(
                'Sending stopped by a problem',
                self::failureDetail($campaign),
                'Fix the sending account, then create a new campaign from this audience. A campaign stopped this '
                    .'way cannot be resumed, and this platform will not move it to another account for you.',
                false,
            ),

            CampaignStatus::Cancelled => new self(
                'Campaign cancelled',
                'You stopped this campaign for good. Everyone still waiting has been recorded as skipped, so the '
                    .'figures above still account for all of them.',
                'Nothing is left to do. Create a new campaign if you want to contact this audience again.',
                false,
            ),

            default => null,
        };
    }

    /**
     * The reason recorded when it stopped, or an honest statement that none was.
     *
     * A failed campaign always carries one — {@see Campaign::markFailed()} requires
     * it — but a row written by an operator or an older version of this code might
     * not, and inventing a plausible cause for a stop this platform cannot explain
     * would be worse than admitting it does not know.
     */
    private static function failureDetail(Campaign $campaign): string
    {
        $reason = trim((string) $campaign->failure_reason);

        return $reason === ''
            ? 'This campaign stopped and no reason was recorded. The recipients that were already sent are counted '
                .'above; nothing else will be sent from it.'
            : $reason;
    }
}

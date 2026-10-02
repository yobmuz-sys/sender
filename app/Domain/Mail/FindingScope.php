<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * Which kinds of traffic one finding constrains.
 *
 * A finding is a fact about the account, not about what it is being used for
 * right now. But whether the fact *blocks* depends on the traffic mode: a
 * missing DMARC record is disqualifying for bulk marketing and a warning for
 * transactional mail. That is a property of the requirement, not of the
 * evidence, so it is recorded once here rather than recomputed by every reader.
 *
 * Without this, a report has to choose one mode and either overstate a problem
 * for transactional traffic or understate it for a campaign — and both of those
 * readings are wrong in the direction that matters.
 *
 * @see TrafficMode for why the two modes differ.
 */
enum FindingScope: string
{
    /**
     * Constrains every mode. Transport encryption and a verified credential are
     * not traffic-dependent: a password sent in clear text is a disclosure
     * whoever it is going to.
     */
    case All = 'all';

    /**
     * Constrains bulk marketing only.
     *
     * Used for the sender authentication records that providers require of bulk
     * senders specifically. The evidence is unchanged in transactional mode —
     * the record is equally absent — but it is not disqualifying there.
     */
    case BulkOnly = 'bulk_only';

    /**
     * Not applicable in transactional mode. Present in the report so the customer
     * can see the requirement before they have a campaign to send, rather than
     * discovering it as a block at the moment they try.
     */
    public function appliesTo(TrafficMode $mode): bool
    {
        return match ($this) {
            self::All => true,
            self::BulkOnly => $mode === TrafficMode::BulkMarketing,
        };
    }

    /**
     * Whether this finding is shown at all in the given mode.
     *
     * Bulk-only findings are shown in transactional mode too, at the reduced
     * level {@see DeliveryReadiness} assigns them there. Hiding them would make
     * a report that says "ready" look identical to one that never checked.
     */
    public function isVisibleIn(TrafficMode $mode): bool
    {
        return true;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use App\Domain\Mail\DeliveryOutcome;

/**
 * What one submission attempt produced.
 *
 * Deliberately narrower than {@see DeliveryOutcome}: that
 * vocabulary is about what a *receiving server* said during verification and
 * sending, while this is about the durable record a campaign leaves behind. The
 * two are related but not identical, and collapsing them would force a history row
 * to carry meanings it should not have — in particular, "blocked" and "skipped"
 * describe platform decisions made *before* anything was sent, and have no SMTP
 * exchange at all.
 *
 * `Accepted` is the only success. It means the transport took the message. Nothing
 * in this vocabulary describes what happened to it afterwards, because nothing has
 * told us yet.
 */
enum AttemptResult: string
{
    /**
     * The server accepted the message for onward delivery.
     */
    case Accepted = 'accepted';

    /**
     * A 4xx or a timeout: the server asked us to come back later.
     */
    case TemporaryFailure = 'temporary_failure';

    /**
     * A 5xx that is not about this recipient, such as a policy refusal.
     */
    case PermanentFailure = 'permanent_failure';

    /**
     * The connection, authentication or transport itself failed.
     *
     * Distinct from a recipient rejection: nothing about this recipient caused it,
     * and no other transport is tried in its place. The correct response is to stop
     * and let a person fix the transport.
     */
    case TransportFailure = 'transport_failure';

    /**
     * The platform refused before contacting any server.
     */
    case Blocked = 'blocked';

    /**
     * There was nothing to send, and no decision was made.
     */
    case Skipped = 'skipped';

    /**
     * Submitted, and the platform never heard what the server said.
     *
     * The one outcome that is neither success nor refusal, and the reason this
     * vocabulary has a case that is deliberately not one of the two. The exchange
     * ended without a status code, so the platform cannot establish whether the
     * provider accepted the message. It is not recorded as accepted, because that
     * would claim a certainty nobody has; and it is not retried, because the
     * message may already be sitting in a provider's queue and sending it again
     * would put two copies of the same mail in one person's inbox.
     *
     * A recipient in this state stays there. Somebody decides whether to send again
     * — not a retry policy, and not a worker on a later pass.
     */
    case Ambiguous = 'ambiguous';

    public function label(): string
    {
        return match ($this) {
            self::Accepted => 'Accepted by the server',
            self::TemporaryFailure => 'Temporary failure',
            self::PermanentFailure => 'Permanent failure',
            self::TransportFailure => 'Transport failure',
            self::Blocked => 'Blocked before sending',
            self::Skipped => 'Skipped',
            self::Ambiguous => 'No reply from the server',
        };
    }

    /**
     * Whether this outcome is worth another attempt later.
     *
     * Only a temporary failure. Everything else is either done or a problem a
     * person has to resolve, and retrying it automatically is how a sender ends up
     * pressing on against a provider that has already said no.
     *
     * `Ambiguous` is in the "no" column deliberately, and the reason is worth stating
     * plainly because it is the one case where the conservative answer and the
     * helpful answer disagree. It would be convenient to treat a missing reply as a
     * connection blip and try again; but a missing reply is exactly what a lost
     * `250` after acceptance looks like, and this platform would then be the thing
     * that decides a provider may deliver the same campaign twice. An operator who
     * knows the first attempt did not land can start a new campaign; the platform
     * cannot know that, so it does not assume it.
     */
    public function isRetryable(): bool
    {
        return $this === self::TemporaryFailure;
    }

    public function tone(): string
    {
        return match ($this) {
            self::Accepted => 'emerald',
            self::TemporaryFailure => 'amber',
            self::PermanentFailure, self::TransportFailure => 'rose',
            self::Blocked, self::Skipped => 'slate',
            self::Ambiguous => 'amber',
        };
    }

    /**
     * What this outcome means, in one sentence a customer can act on.
     *
     * The label says what happened; this says what it means for that recipient. The
     * distinction matters for the three outcomes a customer will want explained:
     * "temporary failure" without a reason reads as a bug, and "the server asked us
     * to come back later" is what tells them their volume was too high.
     */
    public function explanation(): string
    {
        return match ($this) {
            self::Accepted => 'The receiving server took responsibility for this message. That is not a promise it arrived, and nothing here can tell you whether it was opened.',
            self::TemporaryFailure => 'The server asked us to try again later, which usually means it wanted less mail for a while. This recipient is queued for another attempt.',
            self::PermanentFailure => 'The server refused this message and will not accept it again, so it is not retried.',
            self::TransportFailure => 'The connection or the sending account itself failed. Nothing about this recipient caused it.',
            self::Blocked => 'The platform refused before contacting any server: this person had asked not to be contacted before their turn came up.',
            self::Skipped => 'There was nobody to send to. No server was contacted and no decision was made.',
            self::Ambiguous => 'This message was submitted and no reply came back, so we cannot say whether the server took it. It is not being sent again automatically, because if the first attempt did land, sending it twice would deliver it twice.',
        };
    }
}

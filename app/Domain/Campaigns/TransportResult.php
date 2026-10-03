<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use App\Domain\Mail\DeliveryOutcome;

/**
 * What a receiving server said about one submission.
 *
 * The outcome is classified at the boundary and never stored raw, for the reason
 * {@see DeliveryOutcome} documents: an SMTP reply contains a queue id, a remote
 * address and sometimes a policy reference, and none of that belongs in a row the
 * platform will later display. The `detail` is kept alongside it anyway — verbatim,
 * and escaped wherever it is rendered — because paraphrasing the only evidence
 * there is would destroy it.
 *
 * `messageId` is the identifier this platform put in the message, not one the
 * server returned. It is generated before submission precisely so it is known
 * either way: a bounce arriving tomorrow has to be matched to a submission, and
 * asking a provider for an identifier we did not store would be asking for
 * something we then could not have used.
 */
final readonly class TransportResult
{
    public function __construct(
        public DeliveryOutcome $outcome,
        public string $messageId,
        public ?string $code = null,
        public ?string $detail = null,
    ) {}

    public static function accepted(string $messageId): self
    {
        return new self(DeliveryOutcome::Accepted, $messageId);
    }

    public static function failed(
        DeliveryOutcome $outcome,
        string $messageId,
        ?string $code = null,
        ?string $detail = null,
    ): self {
        return new self($outcome, $messageId, $code, $detail);
    }

    public function isAccepted(): bool
    {
        return $this->outcome === DeliveryOutcome::Accepted;
    }

    /**
     * The attempt outcome this produces in the durable history.
     *
     * The mapping is where the two vocabularies meet. A rejected *recipient* is a
     * permanent failure of that message, not of the transport; a rejected
     * *transport* is neither, and stopping the campaign is the right response to
     * both of those rather than retrying them.
     */
    public function attemptResult(): AttemptResult
    {
        return match ($this->outcome) {
            DeliveryOutcome::Accepted => AttemptResult::Accepted,
            DeliveryOutcome::TemporaryFailure => AttemptResult::TemporaryFailure,
            DeliveryOutcome::RecipientRejected,
            DeliveryOutcome::PermanentFailure => AttemptResult::PermanentFailure,
            DeliveryOutcome::AuthenticationRejected,
            DeliveryOutcome::ConnectionFailed => AttemptResult::TransportFailure,
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Audience;

/**
 * What one SMTP recipient check observed.
 *
 * Deliberately a raw observation, not a verdict. The reply code and the enhanced
 * status code are kept exactly as the server gave them, and the decision about
 * what they mean lives in {@see MailboxSmtpValidator} where it can be tested
 * exhaustively without a socket.
 *
 * The response *text* is read and discarded rather than stored. SMTP responses
 * quote the address that was checked and frequently echo the rejected identity,
 * so keeping the transcript would mean keeping a copy of the audience in a log
 * column that outlives the deployment. Nothing here needs it: the classification
 * rules key on the code and the enhanced status, never on the wording.
 */
final readonly class RecipientProbeResult
{
    /**
     * @param  int|null  $code  The three-digit SMTP reply code, or null if no
     *                          reply was received at all.
     * @param  string|null  $enhancedCode  RFC 3463 status, e.g. '5.1.1'.
     * @param  ValidationReason|null  $transportFailure  Set instead of a reply
     *                                                   code when no reply was
     *                                                   possible.
     */
    private function __construct(
        public ?int $code,
        public ?string $enhancedCode,
        public ?ValidationReason $transportFailure = null,
    ) {}

    /**
     * The server replied.
     */
    public static function replied(int $code, ?string $enhancedCode = null): self
    {
        return new self($code, $enhancedCode);
    }

    /**
     * No reply was possible: the connection timed out, was refused, or the host
     * could not be reached.
     *
     * Every one of these is `UNKNOWN`. None of them is evidence about a mailbox.
     */
    public static function unreachable(ValidationReason $reason): self
    {
        return new self(null, null, $reason);
    }

    /**
     * Whether a reply was actually received.
     */
    public function wasAnswered(): bool
    {
        return $this->code !== null;
    }

    /**
     * Whether the reply is a temporary failure, which RFC 5321 reserves for
     * conditions that may clear on its own.
     */
    public function isTemporary(): bool
    {
        return $this->code !== null && $this->code >= 400 && $this->code < 500;
    }

    /**
     * Whether the reply is a permanent failure.
     *
     * Necessary but nowhere near sufficient for a confirmed-invalid verdict: a
     * `550` is a rejection, and a rejection is what anti-enumeration measures are
     * built from. What distinguishes "this mailbox does not exist" from "we will
     * not tell you" is the enhanced status code.
     */
    public function isPermanent(): bool
    {
        return $this->code !== null && $this->code >= 500;
    }

    /**
     * Whether the enhanced status code is one of the definitive ones.
     *
     * The allowlist is three codes long and every entry is a condition about the
     * mailbox or the address rather than about the server's willingness to talk:
     *
     *     5.1.1   bad destination mailbox address
     *     5.1.2   bad destination system address
     *     5.1.3   bad destination mailbox address syntax
     *
     * Nothing else qualifies — including `5.1.4` (ambiguous) and every `5.7.x`
     * policy code, which describe the server's disposition rather than the
     * mailbox's existence. Widening this list is the single change that would do
     * the most damage to this platform's accuracy, which is why it is an
     * allowlist and not a range.
     */
    public function hasDefinitiveEnhancedCode(): bool
    {
        return in_array($this->enhancedCode, ['5.1.1', '5.1.2', '5.1.3'], true);
    }
}

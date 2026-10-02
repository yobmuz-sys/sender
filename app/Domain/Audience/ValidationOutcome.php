<?php

declare(strict_types=1);

namespace App\Domain\Audience;

use DateTimeInterface;

/**
 * One validated address: what was concluded, how, and from what evidence.
 *
 * This is where the accuracy rule is enforced rather than merely intended. The
 * constructor is the only way to build an outcome, and it refuses any pairing of
 * status and reason that would claim more certainty than the evidence supports:
 *
 *   - CONFIRMED_INVALID with a reason that is not definitive is rejected
 *   - CONFIRMED_INVALID without SMTP evidence is rejected
 *   - LIKELY_ACTIVE without a positive recipient acceptance is rejected
 *
 * A developer adding a new check therefore cannot introduce a shortcut. If the
 * evidence is a `550` without an enhanced code, or a timeout, or a `252`, the
 * type will not let it be written as an inactive address.
 *
 * No SMTP transcript is stored, and no server response text is kept: responses
 * quote the recipient address and sometimes the rejected identity, and this
 * record outlives the deployment that made it. Only the status code and the
 * enhanced status code are retained — the two fields a person troubleshooting a
 * classification actually needs.
 */
final readonly class ValidationOutcome
{
    /**
     * @param  int|null  $smtpCode  The RCPT reply code, e.g. 250 or 550.
     * @param  string|null  $enhancedCode  RFC 3463 status, e.g. '5.1.1'.
     */
    private function __construct(
        public ValidationStatus $status,
        public ValidationReason $reason,
        public ValidationMethod $method,
        public ?int $smtpCode = null,
        public ?string $enhancedCode = null,
        public bool $domainIsCatchAll = false,
        public ?DateTimeInterface $checkedAt = null,
    ) {}

    /**
     * Deterministically unusable address.
     *
     * Only for {@see ValidationReason::InvalidSyntax}, which needs no network and
     * admits no ambiguity.
     */
    public static function invalidSyntax(): self
    {
        return new self(
            ValidationStatus::ConfirmedInvalid,
            ValidationReason::InvalidSyntax,
            ValidationMethod::Syntax,
        );
    }

    /**
     * The domain does not exist.
     *
     * Deterministic: a resolver that answers NXDOMAIN has answered, and no
     * mailbox at a non-existent domain can exist.
     */
    public static function domainNotFound(): self
    {
        return new self(
            ValidationStatus::ConfirmedInvalid,
            ValidationReason::DomainNotFound,
            ValidationMethod::DomainDns,
        );
    }

    /**
     * The domain exists but publishes no usable mail route.
     *
     * Also deterministic, but recorded through the mail-route method rather than
     * the DNS method because the distinction matters when explaining it: the
     * domain is real, it just cannot receive mail.
     */
    public static function noMailRoute(): self
    {
        return new self(
            ValidationStatus::ConfirmedInvalid,
            ValidationReason::NoMailRoute,
            ValidationMethod::MailRoute,
        );
    }

    /**
     * The recipient server stated that this mailbox does not exist.
     *
     * Requires the enhanced status code. A bare `550` is a rejection, not a
     * statement about existence, and cannot reach this method.
     *
     * @param  string  $enhancedCode  RFC 3463 status, e.g. '5.1.1'.
     */
    public static function mailboxNotFound(string $enhancedCode, ?int $smtpCode = null): self
    {
        return new self(
            ValidationStatus::ConfirmedInvalid,
            ValidationReason::MailboxNotFound,
            ValidationMethod::SmtpRecipient,
            smtpCode: $smtpCode,
            enhancedCode: $enhancedCode,
        );
    }

    /**
     * The recipient server accepted the address.
     *
     * Records a `250`, never a `252`: RFC 5321 defines 252 as a server stating
     * it *cannot* verify an address, and treating it as acceptance is exactly the
     * false-positive this pipeline exists to avoid.
     */
    public static function likelyActive(?int $smtpCode = null): self
    {
        return new self(
            ValidationStatus::LikelyActive,
            ValidationReason::RecipientAccepted,
            ValidationMethod::SmtpRecipient,
            smtpCode: $smtpCode,
        );
    }

    /**
     * The evidence was inconclusive.
     *
     * Every reason permitted here describes a situation in which the platform
     * could not determine whether the mailbox exists. That includes every timeout,
     * every temporary failure, every 252 and every policy refusal.
     */
    public static function unknown(ValidationReason $reason, ?int $smtpCode = null, ?string $enhancedCode = null): self
    {
        // Guard rather than trust. A caller that reaches for `unknown()` with a
        // definitive reason has made a mistake, and expressing that mistake as an
        // invalid address is the exact outcome the type exists to prevent.
        if ($reason->isDefinitive()) {
            throw new \LogicException('reason '.$reason->value.' is definitive; it must produce a confirmed-invalid outcome');
        }

        return new self(
            ValidationStatus::Unknown,
            $reason,
            $reason === ValidationReason::CatchAll
                ? ValidationMethod::CatchAllProbe
                : ValidationMethod::SmtpRecipient,
            smtpCode: $smtpCode,
            enhancedCode: $enhancedCode,
            domainIsCatchAll: $reason === ValidationReason::CatchAll,
        );
    }

    /**
     * Stamp this outcome as observed at a moment in time.
     *
     * Applied by the pipeline rather than by each check, so every outcome carries
     * a timestamp from one clock and a cached copy of a result keeps the time it
     * was actually observed — not the time it happened to be read.
     */
    public function observedAt(DateTimeInterface $at): self
    {
        return new self(
            $this->status,
            $this->reason,
            $this->method,
            $this->smtpCode,
            $this->enhancedCode,
            $this->domainIsCatchAll,
            $at,
        );
    }

    /**
     * A result the platform has deliberately not checked.
     *
     * Also `UNKNOWN`, and also not eligible to send. A contact that has never
     * been validated must not be indistinguishable from one that was checked and
     * could not be confirmed — the second has evidence, the first does not.
     */
    public static function notValidated(): self
    {
        return new self(
            ValidationStatus::Unknown,
            ValidationReason::NotValidated,
            ValidationMethod::None,
        );
    }

    /**
     * Excluded by policy pending evidence the platform does not have.
     *
     * Reserved. There is deliberately no disposable-address provider wired in and
     * no manufactured risk score: a `RISKY` classification must be traceable to
     * a concrete source, or it is a guess wearing a badge.
     */
    public static function risky(ValidationReason $reason): self
    {
        if ($reason->isDefinitive()) {
            throw new \LogicException('a definitive reason is a confirmed-invalid outcome, not a risky one');
        }

        return new self(
            ValidationStatus::Risky,
            $reason,
            ValidationMethod::None,
        );
    }

    /**
     * A result reused from the mailbox cache.
     *
     * The status, reason and codes are preserved; only the method is restated, so
     * a report never presents a month-old result as a fresh observation.
     */
    public function asCached(): self
    {
        return new self(
            $this->status,
            $this->reason,
            ValidationMethod::Cached,
            $this->smtpCode,
            $this->enhancedCode,
            $this->domainIsCatchAll,
            $this->checkedAt,
        );
    }

    /**
     * The technical explanation shown under "Why?".
     *
     * @return array{method: string, smtp_code: int|null, enhanced_code: string|null, reason: string}
     */
    public function technicalDetail(): array
    {
        return [
            'method' => $this->method->label(),
            'smtp_code' => $this->smtpCode,
            'enhanced_code' => $this->enhancedCode,
            'reason' => $this->reason->technicalDetail(),
        ];
    }

    /**
     * Whether this outcome alone makes the address eligible to send to.
     *
     * Never the whole answer: suppression and consent are applied on top of this
     * and always win. See {@see AudienceEligibility}.
     */
    public function isSendEligible(): bool
    {
        return $this->status->isSendEligible();
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Audience;

/**
 * How an outcome was established.
 *
 * Recorded alongside every result because the strength of a classification is a
 * function of the method that produced it. A `CONFIRMED_INVALID` arrived at
 * through SMTP recipient evidence is a different claim from one arrived at
 * through syntax rules, and an operator reading a report later needs to be able
 * to tell which happened — particularly because the method is what determines
 * how long the result stays trustworthy.
 *
 * `Cached` is a method of its own rather than a flag, so a reused result is
 * never presented as a fresh observation.
 */
enum ValidationMethod: string
{
    /**
     * Deterministic address syntax. No network, no ambiguity, no expiry.
     */
    case Syntax = 'syntax';

    /**
     * Whether the domain exists in DNS.
     */
    case DomainDns = 'domain_dns';

    /**
     * Whether the domain publishes a usable mail route (MX, or the A/AAAA
     * fallback RFC 5321 permits).
     */
    case MailRoute = 'mail_route';

    /**
     * An SMTP recipient check against the domain's own mail server: MAIL FROM,
     * RCPT TO, then RSET. No message is ever transmitted.
     *
     * This is the only method that can establish {@see ValidationStatus::LikelyActive}
     * for an individual mailbox, and the only one that can establish
     * {@see ValidationReason::MailboxNotFound}.
     */
    case SmtpRecipient = 'smtp_recipient';

    /**
     * A synthetic address at the domain was accepted, which proves acceptance
     * carries no information. The domain's results are all `UNKNOWN`.
     */
    case CatchAllProbe = 'catch_all_probe';

    /**
     * A result reused from the mailbox cache, past its original check.
     */
    case Cached = 'cached';

    /**
     * No method has been applied. The initial state of every contact.
     */
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Syntax => 'Address syntax',
            self::DomainDns => 'DNS lookup',
            self::MailRoute => 'Mail route lookup',
            self::SmtpRecipient => 'SMTP recipient validation',
            self::CatchAllProbe => 'Catch-all probe',
            self::Cached => 'Reused from a previous check',
            self::None => 'Not checked',
        };
    }

    /**
     * Whether this method performed an SMTP conversation with a mail server.
     */
    public function involvedSmtp(): bool
    {
        return in_array($this, [self::SmtpRecipient, self::CatchAllProbe], true);
    }
}

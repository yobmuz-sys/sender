<?php

declare(strict_types=1);

namespace App\Domain\Audience;

/**
 * Why an address received the status it did.
 *
 * Machine-readable, one reason per outcome, and a reason is never invented to
 * fill a gap. An address the platform could not classify properly is recorded as
 * `UNKNOWN` with the reason it could not be classified — not as a confident
 * verdict with a convenient explanation attached, which is the failure mode a
 * reason vocabulary exists to prevent.
 *
 * The reasons that may accompany `CONFIRMED_INVALID` are the interesting subset,
 * and the list is deliberately tiny:
 *
 *     INVALID_SYNTAX      the address cannot be an address
 *     DOMAIN_NOT_FOUND    the domain does not exist in DNS
 *     NO_MAIL_ROUTE       the domain exists and accepts no mail
 *     MAILBOX_NOT_FOUND   the recipient server said this mailbox does not exist
 *
 * Each is deterministic and each is checkable. `MAILBOX_NOT_FOUND` is
 * particularly constrained: it requires an enhanced status code of `X.1.1`, not
 * merely a `550`. A `550` on its own is a rejection, and rejections are what
 * anti-enumeration measures are built from — a server that answered "no such
 * mailbox" to everything would leak its entire user list.
 *
 * Everything else — a timeout, a `4xx`, a `252`, a policy refusal, a rate limit,
 * a provider that refuses to answer at all — lands in `UNKNOWN`, and none of
 * them may be recorded as inactive.
 */
enum ValidationReason: string
{
    /* Deterministic failures. These are the only reasons that may accompany
       CONFIRMED_INVALID. */
    case InvalidSyntax = 'invalid_syntax';
    case DomainNotFound = 'domain_not_found';
    case NoMailRoute = 'no_mail_route';
    case MailboxNotFound = 'mailbox_not_found';

    /* Temporary or inconclusive. These may only accompany UNKNOWN. */
    case TemporaryFailure = 'temporary_failure';
    case Timeout = 'timeout';
    case DnsUnavailable = 'dns_unavailable';
    case VerificationBlocked = 'verification_blocked';
    case ProviderProtection = 'provider_protection';
    case PolicyRejection = 'policy_rejection';
    case InconclusiveVerification = 'inconclusive_verification';

    /* Domain-level conditions that remove the ability to confirm any mailbox. */
    case CatchAll = 'catch_all';
    case MailboxDisabled = 'mailbox_disabled';

    /*
     * The address cannot be expressed in ASCII, and this platform cannot
     * canonicalise it. This is a limitation of the checker, not a fact about the
     * mailbox, so it may never be reported as invalid — see SyntaxValidator.
     */
    case InternationalisedAddress = 'internationalised_address';

    /* Positive evidence. The only reason that may accompany LIKELY_ACTIVE. */
    case RecipientAccepted = 'recipient_accepted';

    /* Not yet classified. */
    case NotValidated = 'not_validated';

    /**
     * Reasons that are sufficient on their own to confirm an address is invalid.
     *
     * This list is the formal accuracy rule, in code. Nothing else may produce
     * {@see ValidationStatus::ConfirmedInvalid}, and a future contributor adding
     * a new reason has to decide here, deliberately, whether it qualifies.
     *
     * @return list<self>
     */
    public static function definitive(): array
    {
        return [
            self::InvalidSyntax,
            self::DomainNotFound,
            self::NoMailRoute,
            self::MailboxNotFound,
        ];
    }

    public function isDefinitive(): bool
    {
        return in_array($this, self::definitive(), true);
    }

    /**
     * Whether the platform is structurally unable to confirm this address.
     *
     * Distinct from `definitive()` in both directions: a catch-all domain and a
     * provider that refuses probing are not evidence *for* or *against* any
     * individual mailbox, and a temporary failure says nothing about the next
     * attempt.
     */
    public function isUnresolvableHere(): bool
    {
        return in_array($this, [
            self::CatchAll,
            self::ProviderProtection,
            self::VerificationBlocked,
            self::PolicyRejection,
            self::InconclusiveVerification,
            self::TemporaryFailure,
            self::Timeout,
            self::DnsUnavailable,
            self::MailboxDisabled,
            self::InternationalisedAddress,
        ], true);
    }

    /**
     * The reason shown to a person, in their own terms.
     */
    public function label(): string
    {
        return match ($this) {
            self::InvalidSyntax => 'The address is not a valid email address.',
            self::DomainNotFound => 'The domain in this address does not exist.',
            self::NoMailRoute => 'The domain exists but is not set up to receive email.',
            self::MailboxNotFound => 'The recipient server reported that this mailbox does not exist.',
            self::TemporaryFailure => 'The recipient server reported a temporary problem and asked us to try again.',
            self::Timeout => 'The recipient server did not answer in time.',
            self::DnsUnavailable => 'The domain could not be checked because the DNS lookup did not complete.',
            self::VerificationBlocked => 'The recipient server does not allow reliable mailbox verification.',
            self::ProviderProtection => 'The receiving provider does not allow reliable mailbox verification.',
            self::PolicyRejection => 'The recipient server refused the check for policy reasons.',
            self::InconclusiveVerification => 'The recipient server could not confirm the mailbox either way.',
            self::CatchAll => 'This domain accepts email for any address, so this mailbox cannot be confirmed.',
            self::MailboxDisabled => 'The recipient server reported that the mailbox exists but is not accepting email.',
            self::InternationalisedAddress => 'This address uses non-Latin characters, which this platform cannot '
                .'check reliably. It has been left out of sending rather than marked inactive.',
            self::RecipientAccepted => 'The recipient server accepted the address for delivery.',
            self::NotValidated => 'This address has not been checked yet.',
        };
    }

    /**
     * The technical detail shown under "Why?", never as the primary wording.
     *
     * Names what was observed rather than interpreting it, so an operator reading
     * it learns the same thing the classification did.
     */
    public function technicalDetail(): string
    {
        return match ($this) {
            self::InvalidSyntax => 'Rejected by deterministic address syntax rules.',
            self::DomainNotFound => 'The domain returned NXDOMAIN from DNS.',
            self::NoMailRoute => 'The domain published no MX record and no A/AAAA fallback for mail.',
            self::MailboxNotFound => 'The recipient server returned an enhanced status of X.1.1 for this address.',
            self::TemporaryFailure => 'The recipient server returned a 4xx response.',
            self::Timeout => 'The connection to the recipient server exceeded the configured timeout.',
            self::DnsUnavailable => 'The DNS resolver did not return an answer for this domain.',
            self::VerificationBlocked => 'The recipient server refused the verification command outright.',
            self::ProviderProtection => 'The receiving provider operates anti-enumeration measures that return the '
                .'same answer for existing and non-existing mailboxes.',
            self::PolicyRejection => 'The recipient server refused the check for rate-limiting or policy reasons.',
            self::InconclusiveVerification => 'The recipient server returned a 252 response, which states that it '
                .'cannot verify the address.',
            self::CatchAll => 'A synthetic address at this domain was accepted, so acceptance proves nothing.',
            self::MailboxDisabled => 'The recipient server returned an enhanced status of X.2.1.',
            self::InternationalisedAddress => 'The address contains non-ASCII characters and no punycode '
                .'conversion is available on this host.',
            self::RecipientAccepted => 'The recipient server returned a 250 response to the recipient check.',
            self::NotValidated => 'No check has been performed against this address.',
        };
    }

    /**
     * Whether a person should expect this to change on a later check.
     */
    public function isTransient(): bool
    {
        return in_array($this, [
            self::TemporaryFailure,
            self::Timeout,
            self::DnsUnavailable,
            self::CatchAll,
            self::VerificationBlocked,
            self::ProviderProtection,
            self::PolicyRejection,
            self::InconclusiveVerification,
            self::MailboxDisabled,
            self::InternationalisedAddress,
            self::NotValidated,
        ], true);
    }
}

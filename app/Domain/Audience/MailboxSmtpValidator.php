<?php

declare(strict_types=1);

namespace App\Domain\Audience;

/**
 * Turns one SMTP reply into a verdict, and is where this platform's accuracy
 * rule is actually enforced.
 *
 * RFC 5321 makes an unambiguous reply code optional for a recipient server.
 * `250` accepts; `252` is a server stating it *cannot* verify an address even
 * though it will try to deliver; `450` is a temporary mailbox problem; `550` is a
 * permanent failure of which "no such mailbox" is only one possibility, alongside
 * policy rejection, greylisting and deliberate obfuscation.
 *
 * That last point is why the mapping below is built around the enhanced status
 * code rather than the reply code. A server that answered `550` to every address
 * would leak its entire user list to anyone willing to ask, and the major
 * providers responded by refusing to distinguish at all. So:
 *
 *     550 with no enhanced code   ->  UNKNOWN
 *     550 with 5.1.1              ->  CONFIRMED_INVALID
 *
 * The first is the common case and it is deliberately the cautious one. Treating
 * every `550` as a dead address is how a validator ends up discarding a live
 * audience, and a customer who lost four thousand good addresses because of it
 * has no way to tell that apart from a tool that worked correctly.
 *
 * Nothing here ever produces an `UNKNOWN` as anything else, and nothing produces
 * `CONFIRMED_INVALID` without an allowlisted enhanced code or a deterministic
 * local check.
 */
final class MailboxSmtpValidator
{
    public function __construct(
        private readonly RecipientProber $prober,
    ) {}

    /**
     * Classify one address at one mail host.
     *
     * `$host` is a mail exchanger, not a web server: an MX record's target, or the
     * domain's own address when it relies on the implicit route.
     */
    public function check(string $host, string $address): ValidationOutcome
    {
        return $this->classify($this->prober->probe($host, $address));
    }

    /**
     * The mapping itself, separated from the I/O so it can be exercised against
     * every reply shape a server can produce.
     */
    public function classify(RecipientProbeResult $result): ValidationOutcome
    {
        // No reply at all. The host could not be reached, refused the connection,
        // or timed out. None of these says anything about the mailbox.
        if (! $result->wasAnswered()) {
            return ValidationOutcome::unknown(
                $result->transportFailure ?? ValidationReason::Timeout,
            );
        }

        $code = (int) $result->code;

        // 252: the server states it cannot verify the address. Treating this as
        // either acceptance or absence is the exact error this rule exists to
        // prevent, and RFC 5321 gives it a distinct code precisely so it can be
        // told apart from both.
        if ($code === 252) {
            return ValidationOutcome::unknown(
                ValidationReason::InconclusiveVerification,
                $code,
                $result->enhancedCode,
            );
        }

        // 251: the server is not authoritative for this address and will forward.
        // Forwarding is a routing statement, not an existence statement, and the
        // forward could fail or bounce at the far end.
        if ($code === 251) {
            return ValidationOutcome::unknown(
                ValidationReason::ProviderProtection,
                $code,
                $result->enhancedCode,
            );
        }

        // 2xx other than 250/251/252: accepted. A 250 says the server will take
        // this recipient; it does not say the message will be delivered.
        if ($code >= 200 && $code < 300) {
            return ValidationOutcome::likelyActive($code);
        }

        // Temporary. The server asked us to try again, which is an explicit
        // instruction not to conclude anything.
        if ($result->isTemporary()) {
            return ValidationOutcome::unknown(
                ValidationReason::TemporaryFailure,
                $code,
                $result->enhancedCode,
            );
        }

        // Permanent. This is the only branch that can confirm an address is
        // invalid, and only on an allowlisted enhanced status.
        if ($result->isPermanent()) {
            return $this->classifyPermanent($code, $result->enhancedCode);
        }

        // 1xx, 3xx or anything unrecognised: this platform did not learn anything.
        return ValidationOutcome::unknown(
            ValidationReason::ProviderProtection,
            $code,
            $result->enhancedCode,
        );
    }

    /**
     * A 5xx reply, which is a rejection and not yet a conclusion.
     */
    private function classifyPermanent(int $code, ?string $enhanced): ValidationOutcome
    {
        if ($enhanced === null) {
            // The decisive case. A permanent rejection with no enhanced status is
            // almost always a provider declining to distinguish an existing
            // mailbox from a non-existent one, and reading it as "no such
            // mailbox" would discard real recipients by the thousand.
            return ValidationOutcome::unknown(ValidationReason::ProviderProtection, $code);
        }

        return match ($enhanced) {
            // Bad destination mailbox address. The one code that means what a
            // confirmed-invalid verdict claims it means.
            '5.1.1' => ValidationOutcome::mailboxNotFound($enhanced, $code),

            // Bad destination system address: the domain named in the address
            // cannot receive mail. Equivalent to a domain with no usable route.
            '5.1.2' => ValidationOutcome::noMailRoute(),

            // Bad destination mailbox address syntax: the address cannot be
            // delivered to as written.
            '5.1.3' => ValidationOutcome::invalidSyntax(),

            // Ambiguous destination mailbox address. Explicitly not an answer.
            '5.1.4' => ValidationOutcome::unknown(ValidationReason::ProviderProtection, $code, $enhanced),

            // Mailbox exists but is not accepting mail. Confirms existence, so
            // this is emphatically not invalid — and it is not usable either,
            // which is why it is excluded rather than counted as likely active.
            '5.2.1' => ValidationOutcome::unknown(ValidationReason::MailboxDisabled, $code, $enhanced),

            // 5.7.x is the policy space: greylisting, rate limiting, blocked
            // senders, anti-enumeration. All of them describe the server's
            // disposition toward us, and none of them describes the mailbox.
            default => str_starts_with($enhanced, '5.7.')
                ? ValidationOutcome::unknown(ValidationReason::PolicyRejection, $code, $enhanced)
                : ValidationOutcome::unknown(ValidationReason::ProviderProtection, $code, $enhanced),
        };
    }
}

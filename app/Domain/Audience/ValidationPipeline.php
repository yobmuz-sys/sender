<?php

declare(strict_types=1);

namespace App\Domain\Audience;

/**
 * Validates one address, by composing the checks rather than reimplementing them.
 *
 * The order is the design. Each layer runs only if the previous one did not
 * already produce a definitive answer, and every layer's failure mode is `UNKNOWN`
 * rather than a pass:
 *
 *     1. syntax        deterministic; a failure is CONFIRMED_INVALID
 *     2. mail route    a DNS lookup, cached per domain; a definitive absence is
 *                      CONFIRMED_INVALID, an unreachable resolver is not
 *     3. catch-all     one probe per domain per cache window; a positive result
 *                      turns every mailbox at the domain into UNKNOWN
 *     4. mailbox       an SMTP recipient check, and the only layer that can
 *                      produce LIKELY_ACTIVE or MAILBOX_NOT_FOUND
 *
 * Two absences are worth stating, because both would otherwise be silent bugs:
 *
 *   - there is no probe message. Nothing is ever sent to a recipient to find out
 *     whether they exist.
 *   - there is no step that turns a timeout, a 4xx, a 252, a policy rejection or
 *     an unreachable domain into `CONFIRMED_INVALID`. Each of those returns
 *     through {@see MailboxSmtpValidator} as `UNKNOWN`, and the only path to a
 *     confirmed-invalid verdict runs through one of four named reasons.
 *
 * The mailbox cache is consulted before any network work and is the reason a
 * re-run of the same extraction does not re-probe a list that was checked
 * yesterday. Its TTL is much shorter than the domain cache's, because a mailbox
 * can start refusing mail at any moment while DNS changes on the scale of days.
 */
class ValidationPipeline
{
    public function __construct(
        private readonly SyntaxValidator $syntax,
        private readonly MailRouteResolver $routes,
        private readonly CatchAllDetector $catchAll,
        private readonly MailboxSmtpValidator $mailboxes,
        private readonly DomainValidationCache $domainCache,
        private readonly MailboxValidationCache $mailboxCache,
        private readonly bool $smtpProbingEnabled,
        private readonly int $catchAllTtlSeconds,
    ) {}

    /**
     * Validate one address and cache the result against the tenant's contact.
     *
     * `$userId` is the tenant the address is being validated *for*. It is not
     * decoration: the mailbox cache is scoped by tenant, because two accounts may
     * hold the same address with genuinely different evidence, and letting the
     * second inherit the first's check would let a tenant claim a recipient was
     * validated when it never was.
     */
    public function validate(string $email, int $userId): ValidationResult
    {
        $email = mb_strtolower(trim($email));

        // A cached mailbox result is reused only while it is current. The reason
        // matters: "likely active" is a statement about the past, and a platform
        // that presented a month-old acceptance as a current result would be
        // claiming an evidence it does not have.
        $cached = $this->mailboxCache->fresh($userId, $email);

        if ($cached !== null) {
            return new ValidationResult(
                $email,
                $this->domainOf($email),
                $cached->asCached(),
            );
        }

        $domain = $this->domainOf($email);

        $syntax = $this->syntax->check($email);

        if ($syntax->status === ValidationStatus::ConfirmedInvalid) {
            return $this->remember($userId, $email, $domain, new ValidationResult(
                $email,
                $domain,
                $syntax,
            ));
        }

        // Any other syntax-stage outcome means this platform cannot express the
        // address at all — an internationalised address it cannot canonicalise is
        // the case that exists today. Asking DNS and a mail server about it would
        // produce a confidently-worded reason for a conclusion nobody reached, and
        // replacing the honest "we cannot check this" with "the domain accepts
        // everything" would be worse: the customer would be told their address was
        // undeliverable when the truth is that we never asked properly.
        if ($syntax->reason !== ValidationReason::NotValidated) {
            return $this->remember($userId, $email, $domain, new ValidationResult(
                $email,
                $domain,
                $syntax,
            ));
        }

        $route = $this->domainCache->freshOrResolve($domain, $this->routes);

        if ($route->status === MailRouteStatus::DomainNotFound) {
            return $this->remember($userId, $email, $domain, new ValidationResult(
                $email,
                $domain,
                ValidationOutcome::domainNotFound(),
                $route,
            ));
        }

        if ($route->status === MailRouteStatus::NoRoute) {
            return $this->remember($userId, $email, $domain, new ValidationResult(
                $email,
                $domain,
                ValidationOutcome::noMailRoute(),
                $route,
            ));
        }

        if ($route->status === MailRouteStatus::Unavailable) {
            // The resolver did not answer. That is a gap in our evidence, not a
            // fact about the address, and it must not be reported as invalid.
            return $this->remember($userId, $email, $domain, new ValidationResult(
                $email,
                $domain,
                ValidationOutcome::unknown(ValidationReason::DnsUnavailable),
                $route,
            ));
        }

        // The domain accepts mail. Individual mailbox verification is where the
        // platform either earns an active/inactive answer or admits it has none.
        if (! $this->smtpProbingEnabled) {
            return $this->remember($userId, $email, $domain, new ValidationResult(
                $email,
                $domain,
                ValidationOutcome::unknown(ValidationReason::VerificationBlocked),
                $route,
            ));
        }

        $catchAll = $this->domainCache->catchAllVerdict(
            $domain,
            $route,
            $this->catchAll,
            $this->catchAllTtlSeconds,
        );

        if ($catchAll->suppressesMailboxResults()) {
            // The domain accepted an address that cannot exist, so accepting this
            // one tells us nothing at all. Reporting it as active is the single
            // most damaging false positive this pipeline could produce.
            return $this->remember($userId, $email, $domain, new ValidationResult(
                $email,
                $domain,
                ValidationOutcome::unknown(ValidationReason::CatchAll),
                $route,
                $catchAll,
            ));
        }

        $host = $route->targets[0] ?? $domain;

        return $this->remember($userId, $email, $domain, new ValidationResult(
            $email,
            $domain,
            $this->mailboxes->check($host, $email),
            $route,
            $catchAll,
        ));
    }

    /**
     * Store a result against its mailbox cache entry, with a TTL that depends on
     * what was concluded.
     *
     * The asymmetry is deliberate: a mailbox that does not exist is a stable fact
     * worth keeping, while a mailbox that accepted an address yesterday says
     * nothing about tomorrow and must be re-checked quickly.
     */
    private function remember(int $userId, string $email, string $domain, ValidationResult $result): ValidationResult
    {
        $outcome = $result->outcome->observedAt(now());

        $stored = new ValidationResult($email, $domain, $outcome, $result->mailRoute, $result->catchAll);

        $this->mailboxCache->put($userId, $email, $stored->outcome);

        return $stored;
    }

    /**
     * The domain part of an address, lower-cased.
     *
     * For an address literal the domain is the bracketed value, which no MX
     * lookup can answer for; such an address is therefore resolved as
     * unavailable rather than as invalid.
     */
    private function domainOf(string $email): string
    {
        $at = strrpos($email, '@');

        return $at === false ? '' : substr($email, $at + 1);
    }
}

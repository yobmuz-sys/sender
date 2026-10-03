<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use App\Domain\Mail\DeliveryOutcome;

/**
 * Decides what a server's refusal means, from the text of the exception it raised.
 *
 * A separate class because this is the single most consequential piece of logic in
 * the sending path and it was, until the ambiguous-outcome audit, completely
 * untested: every campaign test substitutes a recording transport, so the real
 * classifier was never compiled by the suite. A decision that decides whether a
 * message is sent again belongs somewhere it can be asserted about directly.
 *
 * Two distinctions do the work.
 *
 * **Recipient or transport.** A `5.1.1` is one address being refused and the rest of
 * the list is fine; a `535` is the platform's credentials being refused and every
 * subsequent message would fail the same way. Treating them alike is how a sender
 * ends up marking a thousand recipients failed because one provider rejected a login.
 *
 * **A refusal or no answer at all.** A `4xx` is the server saying it did not take
 * the message and asking to be left alone, which is an instruction that a retry
 * should follow. An exception carrying no status code says something different
 * entirely: the server said nothing, which after a successful DATA command is
 * indistinguishable from having accepted the message and lost the reply. That case
 * is {@see DeliveryOutcome::Ambiguous} and it is never retryable, because the safe
 * answer to "we do not know" is not the same as the answer to "not yet".
 *
 * Matching on message text is a compromise, and documented as one: Symfony raises a
 * single transport exception for both a refused login and an unreachable host, and
 * separating them properly would mean reimplementing the handshake. The alternative
 * is reporting every failure as "could not connect", which is wrong for the most
 * common misconfiguration there is.
 */
final class SmtpFailureClassifier
{
    /**
     * What kind of problem a failed submission was.
     *
     * @return array{outcome: DeliveryOutcome, code: string|null}
     */
    public function classify(string $exceptionText): array
    {
        $code = $this->code($exceptionText);

        if (preg_match('/authenticat|\b535\b|\b5\.7\.\d+\b|credential/i', $exceptionText) === 1) {
            return ['outcome' => DeliveryOutcome::AuthenticationRejected, 'code' => $code];
        }

        if ($code !== null && $code >= 500 && $code < 600) {
            // 5.1.x is the enhanced status code for "this mailbox does not exist",
            // which is a fact about the recipient rather than about the message.
            $isRecipient = preg_match('/\b5\.1\.\d\b/', $exceptionText) === 1
                || preg_match('/user unknown|no such user|does not exist|mailbox unavailable/i', $exceptionText) === 1;

            return [
                'outcome' => $isRecipient ? DeliveryOutcome::RecipientRejected : DeliveryOutcome::PermanentFailure,
                'code' => $code,
            ];
        }

        if ($code !== null && $code >= 400 && $code < 500) {
            return ['outcome' => DeliveryOutcome::TemporaryFailure, 'code' => $code];
        }

        // No code at all. Not a temporary failure, which is what this used to
        // return and what would have made a lost acknowledgement into a second copy
        // of the same message.
        return ['outcome' => DeliveryOutcome::Ambiguous, 'code' => null];
    }

    /**
     * The SMTP status code in a server's reply, if there is one.
     *
     * Matched as three digits that stand alone, so a queue id or a remote address
     * containing digits is not mistaken for a code — and explicitly not a number
     * that follows a colon or a dot, which is where a port in `smtp.host:587` lives.
     *
     * That exclusion is not cosmetic. A connection that times out produces
     * `Connection to smtp.example.test:587 timed out`, and reading `587` as a status
     * code made a timeout land in the `5xx` branch — reported as a server that refused
     * the message, with a code to prove it. A timeout is the canonical case of having
     * received no answer at all, which is precisely what this class exists to stop
     * calling a refusal. It was found by the first test that drives the real transport
     * rather than a classifier double.
     */
    private function code(string $text): ?string
    {
        return preg_match('/(?<![\w:.])([45]\d{2})(?![\w.])/', $text, $matches) === 1
            ? $matches[1]
            : null;
    }
}

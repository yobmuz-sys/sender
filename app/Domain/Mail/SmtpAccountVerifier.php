<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use App\Domain\System\Mail\SmtpVerification;
use App\Domain\System\Mail\SmtpVerifier;
use Throwable;

/**
 * Runs a verification against one stored account and records the outcome.
 *
 * The verifier proves things; this decides what the account's stored state becomes
 * as a result. Keeping the two apart means the platform capability and a
 * tenant's account are judged by identical logic while their stored states stay
 * separate concerns.
 *
 * "Send test email" is the action that puts mail on the wire, so it is rate
 * limited independently of the cheaper connection probe. A verification endpoint
 * that will send a message on demand is a mail-sending endpoint; without a limit
 * it could be driven from a browser loop or a borrowed session, and the customer
 * would be the one whose provider rate-limits them.
 */
final class SmtpAccountVerifier
{
    public function __construct(
        private readonly SmtpVerifier $verifier,
        private readonly SmtpEndpointPolicy $endpoints,
        private readonly SenderIdentityPolicy $identity,
    ) {}

    /**
     * Prove the configuration, transport, connection and TLS — nothing more.
     *
     * Credentials are not exercised: no recipient is given, so the verifier stops
     * before the acceptance stage and reports honestly that it did not test them.
     */
    public function verifyConnection(SmtpAccount $account): SmtpVerification
    {
        if ($failure = $this->refuseUnusable($account)) {
            return $failure;
        }

        return $this->record($account, fn (): SmtpVerification => $this->verifier->verify($account->transport()));
    }

    /**
     * Prove the configuration, connection, authentication and message acceptance.
     *
     * Acceptance is the strongest claim available from inside this application.
     * It is still not delivery: nothing here observes the recipient's mailbox, and
     * the summary says so.
     */
    public function sendTestMessage(SmtpAccount $account, string $recipient): SmtpVerification
    {
        if ($failure = $this->refuseUnusable($account)) {
            return $failure;
        }

        return $this->record(
            $account,
            fn (): SmtpVerification => $this->verifier->verify($account->transport(), $recipient),
        );
    }

    /**
     * Refuse before opening a socket, for reasons that make a probe meaningless.
     *
     * Attempting anyway would produce a confusing result — a rejected login for a
     * transport with no stored secret — and, for a blocked address, would make
     * the probe itself the SSRF attempt this check exists to prevent.
     */
    private function refuseUnusable(SmtpAccount $account): ?SmtpVerification
    {
        $transport = $account->transport();

        if (! $transport->isSecure()) {
            return SmtpVerification::refused(
                'This transport has no encryption. Its password would cross the network in clear text.',
                $transport->identifier,
            );
        }

        if ($transport->authMode->requiresSecret() && ! $transport->hasSecret()) {
            return SmtpVerification::refused(
                'This transport needs a password and none is stored.',
                $transport->identifier,
            );
        }

        $from = (string) $account->from_address;

        if (! $this->identity->allows($transport, $from)) {
            return SmtpVerification::refused(
                $this->identity->explain($transport, $from),
                $transport->identifier,
            );
        }

        try {
            $this->endpoints->assertConnectable($transport->host);
        } catch (SmtpEndpointRefused $refusal) {
            return SmtpVerification::refused($refusal->getMessage(), $transport->identifier);
        }

        return null;
    }

    /**
     * Run the probe and translate its result into stored account state.
     */
    private function record(SmtpAccount $account, callable $probe): SmtpVerification
    {
        try {
            $verification = $probe();
        } catch (SmtpTransportUnavailable $unavailable) {
            // The stored parameters cannot produce a transport. That is a
            // configuration defect, and it must not be recorded as a server
            // failure — an operator would go looking at the wrong host.
            $account->markFailed(SmtpFailureReason::NotConfigured);

            return SmtpVerification::refused($unavailable->getMessage(), 'smtp:'.$account->id);
        } catch (SmtpEndpointRefused $refusal) {
            $account->markFailed($refusal->reason);

            return SmtpVerification::refused($refusal->getMessage(), 'smtp:'.$account->id);
        } catch (Throwable $exception) {
            $account->markFailed(SmtpFailureReason::ConnectionFailed);

            return SmtpVerification::failed(
                $exception->getMessage(),
                'smtp:'.$account->id,
                SmtpFailureReason::ConnectionFailed,
            );
        }

        if ($verification->proved(SmtpVerification::STAGE_ACCEPTANCE)
            || $verification->proved(SmtpVerification::STAGE_CONNECTION)) {
            // A connection probe that stopped at the socket is not a full
            // verification of the credentials, so it must not mint a READY that
            // claims more than it proved.
            $account->markVerified((int) config('sender.smtp.verification_fresh_after_seconds', 86400));
        } else {
            $account->markFailed($this->reasonFor($verification));
        }

        return $verification;
    }

    /**
     * Map a failed verification onto a category an operator can act on.
     *
     * Read from the stages rather than guessed: an authentication failure and an
     * unreachable host produce the same exception from Symfony, and the stage
     * that failed is the only place the difference is recorded.
     */
    private function reasonFor(SmtpVerification $verification): SmtpFailureReason
    {
        if ($verification->proved(SmtpVerification::STAGE_AUTHENTICATION)) {
            return SmtpFailureReason::TlsFailed;
        }

        if (! $verification->proved(SmtpVerification::STAGE_CONNECTION)) {
            return SmtpFailureReason::ConnectionFailed;
        }

        if (! $verification->proved(SmtpVerification::STAGE_CONFIGURATION)) {
            return SmtpFailureReason::NotConfigured;
        }

        return SmtpFailureReason::Rejected;
    }
}

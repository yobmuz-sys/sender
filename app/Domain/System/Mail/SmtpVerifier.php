<?php

declare(strict_types=1);

namespace App\Domain\System\Mail;

use App\Domain\Mail\MailTransportFactory;
use App\Domain\Mail\SmtpEndpointPolicy;
use App\Domain\Mail\SmtpEndpointRefused;
use App\Domain\Mail\SmtpTransportDefinition;
use App\Domain\System\Enums\CapabilityStatus;
use App\Support\SensitiveData;
use Throwable;

/**
 * Verifies whether this installation can actually send mail.
 *
 * Runs only when an operator asks for it, never during an ordinary request. A
 * network round trip per page view would be unacceptable on shared hosting, and
 * a capability that silently expires between requests is worse than one that
 * honestly reports when it was last established.
 *
 * Verification is staged, and each stage is reported separately, because the
 * stages fail for different reasons and an operator needs to know which one
 * broke:
 *
 *   configuration  a delivering transport is configured at all
 *   transport       Laravel can build that transport from the configuration
 *   connection      the host resolves and accepts a TCP connection, with TLS
 *                   where the scheme calls for it
 *   authentication  the server accepted these credentials
 *   acceptance      the server accepted a test message
 *
 * Acceptance is the strongest claim available from inside the application. It
 * is still not delivery: nothing here observes the recipient's mailbox.
 *
 * Symfony authenticates inside `start()`, so a rejected login and a refused
 * socket both surface as the same exception. The failure is classified and
 * reported against the stage that actually failed: an operator told "could not
 * connect" when the real problem is a bad password would chase the wrong thing.
 *
 * A successful run does not emit a separate `authentication` stage. If the
 * server advertises no authentication mechanism there was nothing to accept,
 * so claiming the stage would be asserting something that never happened; the
 * `acceptance` stage already covers credentials that were offered and taken.
 *
 * Takes an {@see SmtpTransportDefinition} rather than reading `config('mail')`,
 * so the installation's transport and a tenant's account are proved by this one
 * implementation. See {@see SmtpTransportDefinition} for why that matters.
 */
final class SmtpVerifier
{
    public function __construct(
        private readonly MailTransportFactory $mailers,
        private readonly SmtpEndpointPolicy $endpoints,
    ) {}

    /**
     * Mailers that discard mail. Configured deliberately in development, and a
     * silent cause of password resets that never arrive.
     *
     * @var list<string>
     */
    private const NON_DELIVERING = ['log', 'null', 'array', 'failover'];

    /**
     * Verify the installation's own transport.
     *
     * Kept as a separate entry point so the platform's transactional mail keeps
     * a question that only it can answer, and so the capability report cannot
     * be moved by editing some other tenant's account.
     *
     * @param  string|null  $recipient  If given, a test message is sent and the
     *                                  authentication and acceptance stages are proved.
     */
    public function verifyPlatform(?string $recipient = null): SmtpVerification
    {
        $mailer = (string) config('mail.default');

        if (in_array($mailer, self::NON_DELIVERING, true)) {
            $stages = [$this->stage(
                SmtpVerification::STAGE_CONFIGURATION,
                false,
                "mailer '{$mailer}' does not deliver mail",
            )];

            return $this->fail(
                $stages,
                "Mail is configured to use the '{$mailer}' mailer, which discards messages.",
                'Set MAIL_MAILER to smtp (or sendmail) and provide MAIL_HOST and MAIL_PORT.',
                $mailer,
            );
        }

        return $this->verify(
            SmtpTransportDefinition::fromPlatformConfiguration($mailer),
            $recipient,
        );
    }

    /**
     * Verify an arbitrary transport.
     *
     * The same staged probe for the installation's mail and for a tenant's
     * account. Two implementations would be two sets of stage names and two
     * chances to reach different conclusions about the same server — and the
     * whole value of a verification is that it means one specific thing.
     *
     * @param  string|null  $recipient  If given, a test message is sent and the
     *                                  authentication and acceptance stages are proved.
     */
    public function verify(SmtpTransportDefinition $transport, ?string $recipient = null): SmtpVerification
    {
        $stages = [];
        $identifier = $transport->identifier;

        $stages[] = $this->stage(
            SmtpVerification::STAGE_CONFIGURATION,
            true,
            sprintf('%s on %s (%s)', $identifier, $transport->encryption->label(), $transport->endpoint()),
        );

        // The endpoint is checked before anything is dialled. Refusing
        // 10.0.0.5 here is the whole point of the check: once a socket is open
        // the attempt has already happened.
        try {
            $this->endpoints->assertConnectable($transport->host);
        } catch (SmtpEndpointRefused $refusal) {
            $stages[] = $this->stage(SmtpVerification::STAGE_CONFIGURATION, false, $refusal->getMessage());

            return $this->fail(
                $stages,
                'The configured mail server is not one this platform will connect to.',
                null,
                $identifier,
            );
        }

        // Build the transport without opening it. This proves the configuration
        // is coherent before any network I/O is attempted.
        try {
            $built = $this->build($transport);

            $stages[] = $this->stage(
                SmtpVerification::STAGE_TRANSPORT,
                true,
                'transport built from the account configuration',
            );
        } catch (Throwable $exception) {
            $stages[] = $this->stage(SmtpVerification::STAGE_TRANSPORT, false, $exception->getMessage());

            return $this->fail($stages, 'The mail configuration is not valid.', $exception->getMessage(), $identifier);
        }

        // Connect, and negotiate TLS where the scheme requires it. The
        // connection is deliberately left open afterwards: the transport reuses
        // it for the test message, and stopping it here would force a second
        // full round trip to a server that may rate-limit connections.
        try {
            $built->start();

            $stages[] = $this->stage(
                SmtpVerification::STAGE_CONNECTION,
                true,
                $transport->encryption->isSecure()
                    ? 'host resolved, connected, and negotiated '.$transport->encryption->value
                    : 'host resolved and connected without transport encryption',
            );
        } catch (Throwable $exception) {
            // Symfony performs the login inside start(), so a rejected
            // credential and an unreachable host arrive as the same exception.
            // The socket demonstrably connected if we got as far as the login,
            // and reporting it as a connection failure would send an operator
            // looking at the wrong thing.
            if ($this->looksLikeAuthenticationFailure($exception)) {
                $stages[] = $this->stage(SmtpVerification::STAGE_CONNECTION, true, 'host resolved, connected, and negotiated the configured scheme');
                $stages[] = $this->stage(SmtpVerification::STAGE_AUTHENTICATION, false, $exception->getMessage());

                return $this->fail(
                    $stages,
                    'The mail server was reached, but rejected these credentials.',
                    $exception->getMessage(),
                    $identifier,
                );
            }

            $stages[] = $this->stage(SmtpVerification::STAGE_CONNECTION, false, $exception->getMessage());

            return $this->fail($stages, 'Could not open a connection to the mail server.', $exception->getMessage(), $identifier);
        }

        if ($recipient === null || $recipient === '') {
            $built->stop();

            // Honest partial result: everything up to the socket is proved, and
            // the credentials have not been exercised at all.
            return new SmtpVerification(
                CapabilityStatus::Degraded,
                $stages,
                'Connection proved. Credentials were not exercised: no verification address was given.',
                now()->getTimestamp(),
                mailer: $identifier,
            );
        }

        try {
            $accepted = $built->mailer()->raw(
                'Sender platform SMTP verification. If you received this, the transport can submit mail.',
                static function ($message) use ($recipient, $transport): void {
                    $message->to($recipient)->subject('Sender SMTP verification');

                    // Sent from the authenticated identity, because a From that
                    // disagrees with the login is exactly what this platform
                    // refuses to send in normal operation.
                    $from = $transport->permittedFromAddress();

                    if ($from !== null) {
                        $message->from($from);
                    }
                },
            );
        } catch (Throwable $exception) {
            $stages[] = $this->stage(SmtpVerification::STAGE_ACCEPTANCE, false, $exception->getMessage());

            return $this->fail(
                $stages,
                'The mail server was reached, but it rejected these credentials or the message.',
                $exception->getMessage(),
                $identifier,
            );
        } finally {
            // Released on both paths, so a verification never leaks a socket.
            $built->stop();
        }

        $stages[] = $this->stage(
            SmtpVerification::STAGE_ACCEPTANCE,
            (bool) $accepted,
            $accepted ? 'the server accepted a test message' : 'the server declined to accept a test message',
        );

        if (! $accepted) {
            return $this->fail($stages, 'The mail server declined the test message.', null, $identifier);
        }

        return new SmtpVerification(
            CapabilityStatus::Ready,
            $stages,
            'The server accepted a test message. This does not prove recipient delivery.',
            now()->getTimestamp(),
            mailer: $identifier,
        );
    }

    /**
     * Build an isolated mailer for one transport.
     *
     * No global configuration is written. `sender:work` processes several jobs
     * per invocation, and a `config()` mutation left in place would let one
     * tenant's job inherit another's credentials.
     */
    private function build(SmtpTransportDefinition $transport): IsolatedMailer
    {
        return $this->mailers->for($transport);
    }

    /**
     * Whether a transport failure happened after the socket was open, at the
     * login, rather than before it.
     *
     * Matching on the message is a compromise: Symfony raises a single
     * TransportException for both, and distinguishing them properly would mean
     * reimplementing the handshake. The alternative is reporting every failure
     * as "could not connect", which is wrong for the most common misconfiguration
     * there is.
     */
    private function looksLikeAuthenticationFailure(Throwable $exception): bool
    {
        return (bool) preg_match(
            '/authenticat|authenticator|\b535\b|credential|\b5\.7\.8\b/i',
            $exception->getMessage(),
        );
    }

    /**
     * @return array{name: string, passed: bool, detail: string}
     */
    private function stage(string $name, bool $passed, string $detail): array
    {
        return ['name' => $name, 'passed' => $passed, 'detail' => $detail];
    }

    /**
     * @param  list<array{name: string, passed: bool, detail: string}>  $stages
     */
    private function fail(array $stages, string $summary, ?string $error, string $mailer): SmtpVerification
    {
        return new SmtpVerification(
            CapabilityStatus::Unavailable,
            $stages,
            $summary,
            now()->getTimestamp(),
            $error === null ? null : SensitiveData::redactText($error),
            $mailer,
        );
    }
}

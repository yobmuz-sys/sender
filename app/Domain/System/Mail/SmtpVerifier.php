<?php

declare(strict_types=1);

namespace App\Domain\System\Mail;

use App\Domain\System\Enums\CapabilityStatus;
use App\Support\SensitiveData;
use Illuminate\Support\Facades\Mail;
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
 */
final class SmtpVerifier
{
    /**
     * Mailers that discard mail. Configured deliberately in development, and a
     * silent cause of password resets that never arrive.
     *
     * @var list<string>
     */
    private const NON_DELIVERING = ['log', 'null', 'array', 'failover'];

    /**
     * @param  string|null  $recipient  If given, a test message is sent and the
     *                                  authentication and acceptance stages are proved.
     */
    public function verify(?string $recipient = null): SmtpVerification
    {
        $stages = [];
        $mailer = (string) config('mail.default');

        $stages[] = $this->stage(
            SmtpVerification::STAGE_CONFIGURATION,
            ! in_array($mailer, self::NON_DELIVERING, true),
            $mailer === 'smtp' || $mailer === 'sendmail'
                ? "mailer '{$mailer}'"
                : "mailer '{$mailer}' does not deliver mail",
        );

        if (! $stages[0]['passed']) {
            return $this->fail(
                $stages,
                "Mail is configured to use the '{$mailer}' mailer, which discards messages.",
                'Set MAIL_MAILER to smtp (or sendmail) and provide MAIL_HOST and MAIL_PORT.',
            );
        }

        // Build the transport without opening it. This proves the configuration
        // is coherent before any network I/O is attempted.
        try {
            $transport = Mail::mailer()->getSymfonyTransport();

            $stages[] = $this->stage(
                SmtpVerification::STAGE_TRANSPORT,
                true,
                'transport built from the current mail configuration',
            );
        } catch (Throwable $exception) {
            $stages[] = $this->stage(SmtpVerification::STAGE_TRANSPORT, false, $exception->getMessage());

            return $this->fail($stages, 'The mail configuration is not valid.', $exception->getMessage());
        }

        // Connect, and negotiate TLS where the scheme requires it.
        try {
            $transport->start();

            $stages[] = $this->stage(SmtpVerification::STAGE_CONNECTION, true, 'host resolved, connected, and negotiated the configured scheme');
        } catch (Throwable $exception) {
            $stages[] = $this->stage(SmtpVerification::STAGE_CONNECTION, false, $exception->getMessage());

            return $this->fail(
                $stages,
                'Could not open a connection to the mail server.',
                $exception->getMessage(),
            );
        } finally {
            $transport->stop();
        }

        if ($recipient === null || $recipient === '') {
            // Honest partial result: everything up to the socket is proved, and
            // the credentials have not been exercised at all.
            return new SmtpVerification(
                CapabilityStatus::Degraded,
                $stages,
                'Connection proved. Credentials were not exercised: no verification address was given.',
                now()->getTimestamp(),
            );
        }

        try {
            $accepted = Mail::mailer()->raw(
                'Sender platform SMTP verification. If you received this, the installation can submit mail.',
                static function ($message) use ($recipient): void {
                    $message->to($recipient)->subject('Sender SMTP verification');
                },
            );
        } catch (Throwable $exception) {
            $stages[] = $this->stage(SmtpVerification::STAGE_ACCEPTANCE, false, $exception->getMessage());

            return $this->fail(
                $stages,
                'The mail server was reached, but it rejected these credentials or the message.',
                $exception->getMessage(),
            );
        }

        $stages[] = $this->stage(
            SmtpVerification::STAGE_ACCEPTANCE,
            (bool) $accepted,
            $accepted ? 'the server accepted a test message' : 'the server declined to accept a test message',
        );

        if (! $accepted) {
            return $this->fail($stages, 'The mail server declined the test message.', null);
        }

        return new SmtpVerification(
            CapabilityStatus::Ready,
            $stages,
            'The server accepted a test message. This does not prove recipient delivery.',
            now()->getTimestamp(),
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
    private function fail(array $stages, string $summary, ?string $error): SmtpVerification
    {
        return new SmtpVerification(
            CapabilityStatus::Unavailable,
            $stages,
            $summary,
            now()->getTimestamp(),
            $error === null ? null : SensitiveData::redactText($error),
        );
    }
}

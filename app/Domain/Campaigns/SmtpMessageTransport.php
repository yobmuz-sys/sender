<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use App\Domain\Mail\CampaignMessage;
use App\Domain\Mail\DeliveryOutcome;
use App\Domain\Mail\MailTransportFactory;
use App\Domain\Mail\SmtpTransportDefinition;
use Throwable;

/**
 * Submits one campaign message through one tenant's transport.
 *
 * Built per submission and discarded, through {@see MailTransportFactory}, so it
 * cannot inherit another tenant's credentials — the same reason the verifier builds
 * its own mailer rather than using the application mailer. `sender:work` processes
 * many jobs in one invocation, so anything that reached global mail configuration
 * would carry one account's settings into the next message on the wire.
 *
 * Two details that matter more than they look:
 *
 *  - **The message id is generated here**, before submission, and put in the
 *    headers. A bounce will arrive naming a message, and matching it to a
 *    submission requires an identifier the platform stored rather than one it
 *    hoped a provider would return.
 *  - **`List-Unsubscribe` is always sent**, from the platform's own link, in
 *    addition to whatever the customer's template body says. The header is what
 *    mail clients use for a one-click unsubscribe button; the body link is what a
 *    recipient in a plain mail client will actually follow. A campaign is required
 *    to carry the body link by preflight, and the header is not optional even when
 *    the template has one.
 *
 * Failures are classified here and nowhere else, from the server's own reply.
 */
class SmtpMessageTransport implements MessageTransport
{
    public function __construct(private readonly MailTransportFactory $mailers) {}

    public function submit(
        SmtpTransportDefinition $transport,
        CampaignMessage $message,
        string $from,
        string $recipient,
        string $unsubscribeUrl,
    ): TransportResult {
        $messageId = $this->messageId();

        try {
            $built = $this->mailers->for($transport);
        } catch (Throwable $exception) {
            // The transport cannot even be constructed — a missing secret, or an
            // anonymous transport that has one stored. Not a server problem, and
            // not this recipient's.
            return TransportResult::failed(
                DeliveryOutcome::AuthenticationRejected,
                $messageId,
                null,
                $exception->getMessage(),
            );
        }

        $text = $message->text ?? '';

        try {
            $accepted = $built->mailer()->raw($text, function ($message) use (
                $recipient,
                $from,
                $message,
                $unsubscribeUrl,
                $messageId,
            ): void {
                $message->to($recipient)
                    ->from($from)
                    ->subject($message->subject)
                    ->messageId($messageId);

                if ($message->html !== null) {
                    $message->html($message->html);
                }

                if ($message->text !== null) {
                    $message->text($message->text);
                }

                $message->headers->addTextHeader('List-Unsubscribe', '<'.$unsubscribeUrl.'>');
                $message->headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

                // Both, because neither alone is sufficient and adding a header is
                // not deceptive: they say the same thing in the two formats
                // clients and providers each read.
                $message->headers->addTextHeader('Auto-Submitted', 'auto-generated');
            });
        } catch (Throwable $exception) {
            return $this->classify($exception, $messageId);
        } finally {
            $built->stop();
        }

        return $accepted
            ? TransportResult::accepted($messageId)
            : TransportResult::failed(
                DeliveryOutcome::PermanentFailure,
                $messageId,
                null,
                'The server declined the message without giving a reason.',
            );
    }

    /**
     * Read a server's refusal and decide what kind of problem it is.
     *
     * The distinction that carries the weight is recipient versus transport. A
     * `5.1.1` is one address being refused and the rest of the list is fine; a
     * `535` is the platform's credentials being refused and every subsequent
     * message would fail the same way. Treating them alike is how a sender ends up
     * marking a thousand recipients failed because one provider rejected a login.
     *
     * Matching on the message text is a compromise — Symfony raises one transport
     * exception for both a refused login and an unreachable host, and separating
     * them properly would mean reimplementing the handshake. The alternative is
     * reporting every failure as "could not connect", which is wrong for the most
     * common misconfiguration there is.
     */
    private function classify(Throwable $exception, string $messageId): TransportResult
    {
        $text = $exception->getMessage();
        $code = $this->smtpCode($text);

        if (preg_match('/authenticat|\b535\b|\b5\.7\.\d+\b|credential/i', $text) === 1) {
            return TransportResult::failed(DeliveryOutcome::AuthenticationRejected, $messageId, $code, $text);
        }

        if ($code !== null && $code >= 500 && $code < 600) {
            // 5.1.x is the enhanced status code for "this mailbox does not exist",
            // which is a fact about the recipient rather than about the message.
            $isRecipient = preg_match('/\b5\.1\.\d\b/', $text) === 1
                || preg_match('/user unknown|no such user|does not exist|mailbox unavailable/i', $text) === 1;

            return TransportResult::failed(
                $isRecipient ? DeliveryOutcome::RecipientRejected : DeliveryOutcome::PermanentFailure,
                $messageId,
                $code,
                $text,
            );
        }

        if ($code !== null && $code >= 400 && $code < 500) {
            return TransportResult::failed(DeliveryOutcome::TemporaryFailure, $messageId, $code, $text);
        }

        // No code at all means the exchange never got far enough to produce one:
        // DNS, the socket, TLS, or a timeout. The server told us nothing, which is
        // a reason to come back later rather than to give up on the recipient.
        return TransportResult::failed(DeliveryOutcome::TemporaryFailure, $messageId, null, $text);
    }

    /**
     * The SMTP status code in a server's reply, if there is one.
     *
     * Matched as three digits that stand alone, so a queue id or a remote address
     * containing digits is not mistaken for a code.
     */
    private function smtpCode(string $text): ?string
    {
        return preg_match('/\b([45]\d{2})\b/', $text, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * A message identifier this platform controls.
     */
    private function messageId(): string
    {
        return 'campaign-'.bin2hex(random_bytes(12)).'@'.parse_url((string) config('app.url'), PHP_URL_HOST);
    }
}

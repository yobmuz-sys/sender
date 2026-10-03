<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use App\Domain\Mail\CampaignMailerFactory;
use App\Domain\Mail\CampaignMessage;
use App\Domain\Mail\DeliveryOutcome;
use App\Domain\Mail\MailTransportFactory;
use App\Domain\Mail\SmtpTransportDefinition;
use Throwable;

/**
 * Submits one campaign message through one tenant's transport.
 *
 * Built per submission and discarded, through {@see MailTransportFactory},
 * so it cannot inherit another tenant's credentials — the same reason the verifier builds
 * its own mailer rather than using the application mailer. `sender:work` processes
 * many jobs in one invocation, so anything that reached global mail configuration
 * would carry one account's settings into the next message on the wire.
 *
 * Two details that matter more than they look:
 *
 *  - **The message id is put in the headers, and it arrives from the caller.** A
 *    bounce will arrive naming a message, and matching it to a submission requires
 *    an identifier the platform stored rather than one it hoped a provider would
 *    return. It used to be generated in this method, which meant once per attempt:
 *    a retried message went out under a second identifier and the first one became
 *    uncorrelatable. {@see LogicalMessageId} now generates it once per recipient,
 *    and the retry policy cannot reach this code to change it.
 *  - **`List-Unsubscribe` is always sent**, from the platform's own link, in
 *    addition to whatever the customer's template body says. The header is what
 *    mail clients use for a one-click unsubscribe button; the body link is what a
 *    recipient in a plain mail client will actually follow. A campaign is required
 *    to carry the body link by preflight, and the header is not optional even when
 *    the template has one.
 *
 * Failures are classified here and nowhere else, from the server's own reply.
 *
 * The classification refuses to guess in one direction on purpose. An exception
 * with no SMTP status code is reported as {@see DeliveryOutcome::Ambiguous}
 * rather than as a temporary failure, because the two are indistinguishable at
 * the exception and only one of them is safe to act on automatically.
 */
class SmtpMessageTransport implements MessageTransport
{
    public function __construct(
        private readonly CampaignMailerFactory $mailers,
        private readonly SmtpFailureClassifier $classifier,
    ) {}

    public function submit(
        SmtpTransportDefinition $transport,
        CampaignMessage $message,
        string $from,
        string $recipient,
        string $unsubscribeUrl,
        string $messageId,
    ): TransportResult {
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
            /*
             * Three different things, and the difference has to be explicit.
             *
             * `$content` is the `CampaignMessage` this campaign froze at launch —
             * its subject and bodies are what must go on the wire. `$mail` is Laravel's
             * wrapper around the message Symfony is building. `$mime` is that Symfony
             * message, which is the only one of the three that can carry a
             * `Message-ID` or a `List-Unsubscribe` header.
             *
             * They cannot share a name: a closure may not both declare a parameter and
             * import the same variable, so the original version of this line was a
             * fatal error at compile time. It went unnoticed because every campaign
             * test substitutes a recording transport, and the error only appears when
             * this class is loaded — which, in a test suite, it was not.
             */
            $content = $message;

            $accepted = $built->mailer()->raw($text, function ($mail) use (
                $recipient,
                $from,
                $content,
                $unsubscribeUrl,
                $messageId,
            ): void {
                $mail->to($recipient)
                    ->from($from)
                    ->subject($content->subject);

                if ($content->html !== null) {
                    $mail->html($content->html);
                }

                if ($content->text !== null) {
                    $mail->text($content->text);
                }

                /*
                 * Headers go on the MIME message, not on Laravel's wrapper.
                 *
                 * `$mail->headers` and `$mail->messageId()` were both calls to
                 * methods that do not exist: `Illuminate\Mail\Message` forwards
                 * unknown methods to the Symfony `Email`, which offers neither
                 * `headers()` nor `messageId()`. Both raise
                 * `BadMethodCallException` — on every single submission, from the
                 * same never-executed method as the compile error above.
                 *
                 * Symfony generates a `Message-ID` of its own only when the message
                 * has none, so setting it here produces exactly one header. A second
                 * would leave a receiving server to pick which identifier to quote
                 * back at us, which is not a thing to leave to chance when the
                 * identifier is the correlation key for every future bounce.
                 */
                $mime = $mail->getSymfonyMessage();

                $mime->getHeaders()->addIdHeader('Message-ID', $messageId);
                $mime->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$unsubscribeUrl.'>');
                $mime->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

                // Both, because neither alone is sufficient and adding a header is
                // not deceptive: they say the same thing in the two formats
                // clients and providers each read.
                $mime->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');
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
     * Delegated rather than inlined, because this is the decision that decides
     * whether a message is sent again and until the ambiguous-outcome audit it was
     * never compiled by a single test: every campaign test substitutes a recording
     * transport. {@see SmtpFailureClassifier} holds the reasoning.
     */
    private function classify(Throwable $exception, string $messageId): TransportResult
    {
        $text = $exception->getMessage();
        $classified = $this->classifier->classify($text);

        return TransportResult::failed($classified['outcome'], $messageId, $classified['code'], $text);
    }
}

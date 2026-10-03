<?php

declare(strict_types=1);

namespace Tests\Feature\Fakes;

use App\Domain\Mail\CampaignMailerFactory;
use App\Domain\Mail\IsolatedMailer;
use App\Domain\Mail\SmtpTransportDefinition;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * A mailer factory whose only difference from the real one is the socket.
 *
 * Everything else is real: the real `SmtpMessageTransport`, the real
 * `Illuminate\Mail\Mailer`, the real Symfony `Email` the closure is handed, the real
 * headers it adds. Only the transport at the end is replaced, with one that keeps
 * the message instead of sending it.
 *
 * That distinction is the whole value of this class. A test that asserts on a
 * recording transport asserts on the campaign engine. This asserts on the code that
 * puts a message on the wire — the code that held a fatal error at compile time
 * which no test ever loaded, because reaching it required a socket and nobody had
 * one.
 */
final class CapturingMailerFactory implements CampaignMailerFactory
{
    /** @var list<Email> */
    public array $built = [];

    /** @var list<Envelope> */
    public array $envelopes = [];

    private bool $accept = true;

    private ?string $reply = null;

    public function __construct(private readonly ViewFactory $views) {}

    public function for(SmtpTransportDefinition $transport): IsolatedMailer
    {
        return new IsolatedMailer(new CapturedTransport($this), $this->views);
    }

    /**
     * Answer every submission with a refusal instead of an acceptance.
     *
     * `550 5.1.1` is the ordinary permanent-failure reply, and using it proves the
     * real transport's failure path works, not only its happy path. The exception is
     * Symfony's own, thrown the way `send()` throws one, so the production
     * classifier reads the same message text it will read in production.
     */
    public function refusing(string $reply = 'Email transport error: 550 5.1.1 Mailbox unavailable'): self
    {
        $this->accept = false;
        $this->reply = $reply;

        return $this;
    }

    /**
     * The last message built, as a receiving server would have parsed it.
     */
    public function lastMessage(): ?Email
    {
        return $this->built === [] ? null : $this->built[count($this->built) - 1];
    }

    /**
     * Every `Message-ID` header on the last message, whatever case it carries.
     *
     * Two of them means a receiving server sees an identifier this platform did not
     * intend, and which one it honours is not something to leave to chance.
     *
     * @return list<string>
     */
    public function lastMessageIdHeaders(): array
    {
        $message = $this->lastMessage();

        if ($message === null) {
            return [];
        }

        $found = [];

        foreach ($message->getHeaders()->all() as $name => $values) {
            if (strtolower((string) $name) !== 'message-id') {
                continue;
            }

            // Symfony normalises header storage to a list, but a single header added
            // through the convenience methods may still be yielded on its own, so
            // both shapes are handled rather than assumed.
            foreach (is_array($values) ? $values : [$values] as $value) {
                $found[] = $value->getBodyAsString();
            }
        }

        return $found;
    }

    /**
     * Called by {@see CapturedTransport} in place of a network send.
     *
     * @throws TransportException when this factory was told to refuse
     */
    public function record(RawMessage $message, Envelope $envelope): void
    {
        if ($message instanceof Email) {
            $this->built[] = $message;
        }

        $this->envelopes[] = $envelope;

        if (! $this->accept) {
            throw new TransportException((string) $this->reply);
        }
    }
}

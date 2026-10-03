<?php

declare(strict_types=1);

namespace Tests\Feature\Fakes;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * The last stop before the network.
 *
 * Keeps what it was handed and reports the same acceptance a server would, so the
 * code under test cannot tell the difference between this and a real connection.
 * That is the point: everything above it — the mailer, the closure, the headers — is
 * the production code path, and this is the one line that is not.
 */
final class CapturedTransport implements TransportInterface
{
    public function __construct(private readonly CapturingMailerFactory $factory) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $envelope ??= Envelope::create($message);

        $this->factory->record($message, $envelope);

        return new SentMessage($message, $envelope);
    }

    public function start(): void {}

    public function stop(): void {}

    /**
     * Symfony's `TransportInterface` extends `Stringable`, and its own transports
     * render as the `smtp://` DSN they were built for. This one says what it is.
     */
    public function __toString(): string
    {
        return 'captured://in-process';
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Mail\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Throwable;

/**
 * A mailer bound to one transport, opened on demand.
 *
 * Exists so a verification can hold the socket open across the connection probe
 * and the acceptance probe. Symfony authenticates inside `start()`, which means
 * a rejected login and an unreachable host arrive as the same exception; keeping
 * the connection lets the caller tell those apart from the transport's own
 * post-authentication state, and avoids a second full handshake to a server that
 * may be rate-limiting connections.
 *
 * Holds no references to any other transport. Nothing here can reach global mail
 * configuration, which is the property {@see MailTransportFactory} exists to
 * guarantee.
 */
final class IsolatedMailer
{
    private bool $started = false;

    public function __construct(
        private readonly EsmtpTransport $transport,
        private readonly ViewFactory $views,
    ) {}

    /**
     * Open the connection. Safe to call when already open.
     *
     * @throws Throwable
     */
    public function start(): void
    {
        if ($this->started) {
            return;
        }

        $this->transport->start();
        $this->started = true;
    }

    /**
     * Close the connection, ignoring a failure to do so.
     *
     * Called from a `finally`, so it must not mask the outcome being reported.
     */
    public function stop(): void
    {
        if (! $this->started) {
            return;
        }

        $this->started = false;

        try {
            $this->transport->stop();
        } catch (Throwable) {
            // A socket that will not close cleanly is a leak we cannot fix here,
            // and must not be turned into a reported verification failure.
        }
    }

    /**
     * The underlying mailer, for sending one message.
     */
    public function mailer(): Mailer
    {
        return new Mailer('smtp', $this->transport, $this->views);
    }

    public function isStarted(): bool
    {
        return $this->started;
    }
}

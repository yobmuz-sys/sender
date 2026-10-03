<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use App\Domain\Campaigns\SmtpMessageTransport;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Mail\Mailer;
use Symfony\Component\Mailer\Transport\TransportInterface;
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
 *
 * The transport is held as Symfony's interface rather than as `EsmtpTransport`.
 * Everything this class calls on it — `start()`, `stop()`, and handing it to a
 * mailer — is interface contract, and {@see MailTransportFactory} is the only thing
 * that constructs an SMTP one. Naming the concrete class here would have made the
 * message-building path untestable without a socket, which is the same blind spot
 * that hid a compile error in {@see SmtpMessageTransport} for
 * as long as this application existed.
 */
final class IsolatedMailer
{
    private bool $started = false;

    public function __construct(
        private readonly TransportInterface $transport,
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
     *
     * The argument order matters and used to be wrong. Laravel's `Mailer` takes
     * `($name, $views, $transport)`, and this passed the transport second and the
     * views third — which is an immediate `TypeError` on every submission, raised
     * inside the transport's `try` block and therefore reported as an ambiguous
     * failure against the message. So it did not crash the campaign loudly; it made
     * every campaign send nothing and record every recipient as unacknowledged.
     *
     * It survived because nothing could reach it. The only caller of this method is
     * the campaign submission path, and every campaign test substitutes a recording
     * transport, so the line was never executed by anything in this repository.
     * {@see CampaignMailerFactory} exists so a test can now reach
     * it.
     */
    public function mailer(): Mailer
    {
        return new Mailer('smtp', $this->views, $this->transport);
    }

    public function isStarted(): bool
    {
        return $this->started;
    }
}

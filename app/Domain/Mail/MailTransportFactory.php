<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/**
 * Builds a mailer for one transport, without touching global state.
 *
 * The obvious implementation is wrong here, and wrong in a way this application
 * is specifically built to avoid:
 *
 *     Config::set('mail.mailers.smtp', [...$userConfig]);
 *
 * That works, and it is fine in a web request that ends immediately. This
 * platform runs queued work through `sender:work`, which processes *many* jobs
 * in one invocation. Configuration mutated for one job is still mutated when the
 * next job starts, so User B's message could be submitted through User A's
 * credentials — from a shared host, against A's provider, under A's reputation,
 * with A's password on the wire. Nothing in that chain would fail loudly.
 *
 * So a transport is constructed explicitly, from the parameters of one account,
 * and discarded. There is no global configuration to inherit, and therefore no
 * way for one account's settings to become another's.
 *
 * Each call produces a fresh transport, which is also what keeps a connection
 * from being reused across accounts.
 */
final class MailTransportFactory implements CampaignMailerFactory
{
    public function __construct(private readonly ViewFactory $views) {}

    /**
     * A mailer bound to exactly this transport.
     */
    public function for(SmtpTransportDefinition $transport): IsolatedMailer
    {
        return new IsolatedMailer($this->build($transport), $this->views);
    }

    /**
     * The Symfony transport for one set of parameters.
     *
     * @see self::for() for why this is never a global
     */
    private function build(SmtpTransportDefinition $transport): EsmtpTransport
    {
        if ($transport->authMode->requiresSecret() && ! $transport->hasSecret()) {
            // Refused before construction. A password-mode transport with no
            // secret would otherwise be attempted as an anonymous relay, and a
            // failure at that point reads as a server problem.
            throw SmtpTransportUnavailable::because('This transport needs a password but none is stored.');
        }

        if ($transport->authMode === SmtpAuthMode::None && $transport->hasSecret()) {
            // A stored secret with no mechanism to present it would sit in the
            // database unused and suggest a configuration that is not the one
            // being used.
            throw SmtpTransportUnavailable::because('This transport is set to send no authentication, so a stored password would never be used.');
        }

        // Implicit TLS is negotiated from the first byte, so there is no upgrade
        // to request. The `false` argument disables TLS entirely, which happens
        // only for an explicitly unencrypted diagnostic transport.
        $symfonyTransport = new EsmtpTransport(
            $transport->host,
            $transport->port,
            $transport->encryption === SmtpEncryption::ImplicitTls,
        );

        $stream = $symfonyTransport->getStream();

        if ($stream instanceof SocketStream) {
            $stream->setTimeout((float) max(1, $transport->timeoutSeconds));

            // Peer and hostname verification are never disabled. Turning them
            // off would remove the only thing that makes a connection to a
            // *named* host trustworthy, and a tenant-chosen host is precisely
            // the case where an attacker controls the name. There is no
            // configuration path to this.
            $stream->setStreamOptions(array_replace_recursive($stream->getStreamOptions(), [
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                ],
            ]));
        }

        if ($transport->localDomain !== null && $transport->localDomain !== '') {
            $symfonyTransport->setLocalDomain($transport->localDomain);
        }

        if ($transport->encryption === SmtpEncryption::StartTls) {
            // Both halves of the requirement: upgrade when the server offers it,
            // and refuse to proceed in the clear when it does not. With only
            // `autoTls`, a server declining STARTTLS would be used unencrypted
            // and the password would go out in the clear — a silent downgrade.
            $symfonyTransport->setAutoTls(true);
            $symfonyTransport->setRequireTls(true);
        } elseif ($transport->encryption === SmtpEncryption::None) {
            $symfonyTransport->setAutoTls(false);
            $symfonyTransport->setRequireTls(false);
        }

        if ($transport->authMode === SmtpAuthMode::Password) {
            $symfonyTransport->setUsername((string) $transport->username);
            $symfonyTransport->setPassword((string) $transport->secret());
        }

        return $symfonyTransport;
    }
}

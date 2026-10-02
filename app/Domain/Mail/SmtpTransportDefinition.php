<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * A resolved set of SMTP connection parameters.
 *
 * The point of this type is that one verifier can prove one thing regardless of
 * where the configuration came from. Before it existed, the verifier read
 * `config('mail')` directly, which meant it could only ever check the
 * installation's own transport — and a second verifier for user accounts would
 * have been a copy of the first, free to drift from it.
 *
 * A secret is accepted as a plain string and never exposed by a getter. The
 * only way to read it is {@see secret()}, which exists so the transport factory
 * can build a mailer and so a caller can confirm a secret is present. Anything
 * that logs, serialises or renders this object therefore cannot leak it by
 * accident, which is a property worth more than a convenience accessor.
 */
final readonly class SmtpTransportDefinition
{
    public function __construct(
        public string $host,
        public int $port,
        public SmtpEncryption $encryption,
        public SmtpAuthMode $authMode,
        public ?string $username,
        private ?string $secret,
        /**
         * What this transport is called, for run records and diagnostics:
         * `platform`, an account label, or a mailer name.
         */
        public string $identifier = 'platform',
        public ?string $localDomain = null,
        public int $timeoutSeconds = 10,
    ) {}

    /**
     * The installation's own transport, as configured in the environment.
     *
     * This is the path the existing capability keeps using. It is constructed
     * from configuration rather than from a database row precisely so that the
     * platform's transactional mail stays in the environment where an operator
     * expects to find it, and cannot be edited from a browser.
     */
    public static function fromPlatformConfiguration(?string $identifier = null): self
    {
        return new self(
            host: (string) config('mail.mailers.smtp.host', '127.0.0.1'),
            port: (int) config('mail.mailers.smtp.port', 2525),
            encryption: self::encryptionForScheme((string) config('mail.mailers.smtp.scheme')),
            authMode: config('mail.mailers.smtp.username') === null
                ? SmtpAuthMode::None
                : SmtpAuthMode::Password,
            username: config('mail.mailers.smtp.username') === null
                ? null
                : (string) config('mail.mailers.smtp.username'),
            secret: config('mail.mailers.smtp.password') === null
                ? null
                : (string) config('mail.mailers.smtp.password'),
            identifier: $identifier ?? (string) config('mail.default'),
            localDomain: config('mail.mailers.smtp.local_domain') === null
                ? null
                : (string) config('mail.mailers.smtp.local_domain'),
        );
    }

    /**
     * Map Laravel's `MAIL_SCHEME` onto the encryption vocabulary.
     *
     * An unset scheme means STARTTLS, which is what Laravel and Symfony both
     * assume for a plain `smtp` transport on a modern relay.
     */
    public static function encryptionForScheme(?string $scheme): SmtpEncryption
    {
        return match (strtolower((string) $scheme)) {
            'smtps', 'tls', 'ssl' => SmtpEncryption::ImplicitTls,
            'smtp', '', 'starttls' => SmtpEncryption::StartTls,
            'null', 'none' => SmtpEncryption::None,
            default => SmtpEncryption::StartTls,
        };
    }

    /**
     * The one way to read the secret.
     *
     * Named to make the access visible at every call site: a reviewer looking
     * at a diff can see that a transport was built, and separately that a
     * message was sent.
     */
    public function secret(): ?string
    {
        return $this->secret;
    }

    public function hasSecret(): bool
    {
        return $this->secret !== null && $this->secret !== '';
    }

    /**
     * Whether the connection is encrypted.
     *
     * The single definition of "secure", so eligibility and display cannot
     * disagree about it.
     */
    public function isSecure(): bool
    {
        return $this->encryption->isSecure();
    }

    /**
     * `host:port`, the form an error message and a log line both need.
     *
     * Deliberately never includes the secret or the username. This is the
     * strongest thing that may appear in a scheduled-run error.
     */
    public function endpoint(): string
    {
        return $this->host.':'.$this->port;
    }

    /**
     * The effective `From` identity permitted by this transport.
     *
     * Stage 5A ties the From address to the authenticated username, because that
     * is the strongest identity evidence the platform can produce on its own:
     * the server accepted a login as this address. A tenant who needs to send
     * as a different address needs an explicit, verified alias — which is a
     * separate stage, not a field left free here.
     *
     * See {@see SenderIdentityPolicy} for the rule itself.
     */
    public function permittedFromAddress(): ?string
    {
        return $this->username;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * How a transport secures the connection.
 *
 * The three are not interchangeable and the difference is not cosmetic. `none`
 * sends credentials in clear text on the wire, which for a platform holding a
 * tenant's mailbox password is a disclosure to anyone on the path.
 */
enum SmtpEncryption: string
{
    /**
     * TLS from the first byte (implicit TLS, historically SMTPS).
     *
     * Port 465 by convention. Nothing is said in the clear beforehand, not even
     * the greeting.
     */
    case ImplicitTls = 'tls';

    /**
     * Upgrade a plain connection with STARTTLS.
     *
     * Port 587 by convention. Notably, the server greeting is read before the
     * upgrade, so a hostile server can still see that someone connected.
     */
    case StartTls = 'starttls';

    /**
     * No transport encryption.
     *
     * Permitted so an operator can diagnose a relay, and because refusing to
     * represent it would make a misconfigured account unrepresentable — and
     * therefore invisible rather than refused. Never eligible for sending.
     *
     * @see SmtpAccountStatus::isSecure()
     */
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::ImplicitTls => 'SSL/TLS (implicit, usually port 465)',
            self::StartTls => 'STARTTLS (usually port 587)',
            self::None => 'None (unencrypted — not usable for sending)',
        };
    }

    /**
     * Symfony's scheme name for this encryption mode.
     */
    public function scheme(): string
    {
        return match ($this) {
            self::ImplicitTls => 'smtps',
            self::StartTls => 'smtp',
            self::None => 'smtp',
        };
    }

    public function isSecure(): bool
    {
        return $this !== self::None;
    }
}

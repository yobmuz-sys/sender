<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * How a transport authenticates.
 *
 * Only the mechanism this platform can actually perform is represented. OAuth or
 * provider-specific token flows are not options here, because offering them in a
 * form that cannot complete them is how a user ends up believing they configured
 * something that will fail at the first send.
 *
 * @see SmtpAccountStatus for why an unauthenticated transport is not eligible
 */
enum SmtpAuthMode: string
{
    /**
     * Username and password (or an app password).
     *
     * The only mechanism Gmail, Workspace, cPanel and the overwhelming majority
     * of relays offer for this configuration.
     */
    case Password = 'password';

    /**
     * No credentials offered.
     *
     * Only meaningful for an open relay on a trusted network — in practice, for
     * diagnosing one. Never eligible for sending.
     */
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Password => 'Username and password',
            self::None => 'No authentication (not usable for sending)',
        };
    }

    public function requiresSecret(): bool
    {
        return $this === self::Password;
    }
}

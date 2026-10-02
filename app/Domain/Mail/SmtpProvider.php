<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * A named set of SMTP defaults.
 *
 * A preset supplies labels, defaults and constraints. It is not a transport
 * implementation and it is not a code path: every preset resolves to the same
 * generic SMTP fields, and the stored account records a host and a port like any
 * other. That is deliberate — a per-provider transport implementation is where
 * vendor quirks turn into permanent special cases, and it would mean a provider
 * this platform has never heard of could not be configured at all.
 *
 * The Gmail entry carries no quota and no sending limit. Those are Google-side
 * constraints on the customer's mailbox; the platform does not know them, must
 * not hard-code them, and must not work around them.
 */
enum SmtpProvider: string
{
    case Custom = 'custom';
    case Gmail = 'gmail';
    case GoogleWorkspace = 'google_workspace';
    case CPanel = 'cpanel';

    public function label(): string
    {
        return match ($this) {
            self::Custom => 'Custom SMTP',
            self::Gmail => 'Gmail',
            self::GoogleWorkspace => 'Google Workspace',
            self::CPanel => 'cPanel / shared hosting',
        };
    }

    public function defaultHost(): ?string
    {
        return match ($this) {
            self::Gmail, self::GoogleWorkspace => 'smtp.gmail.com',
            self::CPanel, self::Custom => null,
        };
    }

    /**
     * The port Google documents for this configuration.
     *
     * Only a default. The operator may choose 465 or 587, and both are
     * supported; this is not a restriction the platform imposes.
     */
    public function defaultPort(): ?int
    {
        return match ($this) {
            self::Gmail, self::GoogleWorkspace => 587,
            self::CPanel, self::Custom => null,
        };
    }

    public function defaultEncryption(): SmtpEncryption
    {
        return match ($this) {
            self::Gmail, self::GoogleWorkspace => SmtpEncryption::StartTls,
            self::CPanel, self::Custom => SmtpEncryption::StartTls,
        };
    }

    public function defaultAuthMode(): SmtpAuthMode
    {
        // Every provider this lists authenticates. A "no authentication"
        // default for a hosted mailbox would produce a configuration that fails
        // on first use.
        return SmtpAuthMode::Password;
    }

    /**
     * Whether this is a hosted relay the customer does not operate.
     *
     * Load-bearing for two separate findings:
     *
     *  - Reverse DNS of the observed endpoint says nothing about the sending
     *    infrastructure, because the provider submits onward from addresses the
     *    customer never sees.
     *  - Deliverability preflight findings against the *provider* are about the
     *    provider, not the customer's own setup.
     *
     * A custom host is assumed to be the customer's own until proven otherwise,
     * because refusing to check a tenant's own infrastructure would leave the one
     * case they can actually fix unexamined.
     */
    public function isThirdPartyRelay(): bool
    {
        return $this === self::Gmail
            || $this === self::GoogleWorkspace
            || $this === self::CPanel;
    }

    /**
     * Guidance shown beside the form.
     *
     * The Gmail text says what Google's documentation says, and says plainly
     * that the customer should not paste their ordinary account password here.
     */
    public function guidance(): string
    {
        return match ($this) {
            self::Gmail, self::GoogleWorkspace => implode("\n", [
                'Google documents smtp.gmail.com with port 465 (SSL/TLS) or 587 (STARTTLS),',
                'and authentication required.',
                '',
                'For password-based SMTP, Google provides an App Password for this setup',
                'where your account and policy allow it. App Passwords require',
                '2-Step Verification. Do not enter your ordinary Google account password.',
                '',
                'Gmail and Workspace enforce their own sending limits and anti-abuse',
                'systems. Configuring this transport does not change them, and this',
                'platform does not attempt to work around them.',
            ]),
            self::CPanel => implode("\n", [
                'Your host provides the SMTP settings for your domain, usually in',
                'cPanel under Email Accounts -> Connect Devices. The hostname is often',
                'mail.yourdomain.com with port 465 for SSL/TLS.',
                '',
                'A server on 127.0.0.1 or a private address is refused: this platform',
                'will only connect to a publicly routable destination.',
            ]),
            self::Custom => 'Enter the SMTP settings issued by your mail provider.',
        };
    }
}

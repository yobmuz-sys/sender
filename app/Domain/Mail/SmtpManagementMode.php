<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * Where an account came from, which decides who may change it.
 *
 * One table serves both cases. The alternative — separate user-owned and
 * admin-owned tables — would mean the same transport shape expressed twice, two
 * migrations to keep in step, and no way to answer "is this account the
 * platform's to change?" with a single column read.
 *
 * The rule this encodes is deliberate: an administrator who configures a
 * transport for a tenant is not offering it as a suggestion. A tenant editing
 * the host of an admin-managed account would be editing a credential the
 * operator supplied, so the tenant sees the metadata and cannot change it.
 */
enum SmtpManagementMode: string
{
    case UserManaged = 'user_managed';

    case AdminManaged = 'admin_managed';

    public function label(): string
    {
        return match ($this) {
            self::UserManaged => 'Managed by the account owner',
            self::AdminManaged => 'Managed by platform staff',
        };
    }

    /**
     * Whether the owning user may edit this account.
     *
     * `false` means read-only for the owner. Enforced in the controller, not
     * inferred from the role, so a support account with mail permissions still
     * cannot silently take ownership of an operator's transport.
     */
    public function ownerMayEdit(): bool
    {
        return $this === self::UserManaged;
    }
}

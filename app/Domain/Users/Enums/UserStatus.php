<?php

declare(strict_types=1);

namespace App\Domain\Users\Enums;

/**
 * Whether an account can sign in.
 *
 * Explicit rather than inferred from the role, for two reasons. A suspended
 * super administrator must still be identifiable as a super administrator —
 * their authority did not stop existing, their access did — and a platform that
 * lacks a status column has nowhere to record "temporarily locked" without
 * abusing the role.
 */
enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
        };
    }

    public function canSignIn(): bool
    {
        return $this === self::Active;
    }
}

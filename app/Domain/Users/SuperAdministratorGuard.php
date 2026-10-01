<?php

declare(strict_types=1);

namespace App\Domain\Users;

use App\Domain\Users\Enums\Role;
use App\Domain\Users\Enums\UserStatus;
use App\Models\User;

/**
 * The platform's own rule about locking itself out.
 *
 * A deployment with no way to sign in as an administrator is unrecoverable
 * without database access, which a customer on shared hosting does not have.
 * The rule is therefore enforced in one place that every mutation goes through,
 * rather than trusted to each controller remembering.
 *
 * It exists only for the highest authority. Lower roles can all be revoked
 * without consequence, because a super administrator can restore them.
 */
final class SuperAdministratorGuard
{
    /**
     * Reason for a refusal, or null when the change is permitted.
     */
    public function refusalFor(User $target, ?Role $newRole = null, ?UserStatus $newStatus = null): ?string
    {
        if (! $target->isSuperAdministrator()) {
            return null;
        }

        $losingAccess = ($newStatus !== null && ! $newStatus->canSignIn())
            || ($newRole !== null && ! $newRole->isStaff());

        if (! $losingAccess) {
            return null;
        }

        // Only matters when this would be the last one that still has access.
        return $this->remainingActiveSuperAdministrators($target) === 0
            ? 'This is the last super administrator with access. Promote another account first.'
            : null;
    }

    public function assertMayChange(User $target, ?Role $newRole = null, ?UserStatus $newStatus = null): void
    {
        $refusal = $this->refusalFor($target, $newRole, $newStatus);

        if ($refusal !== null) {
            throw new LastSuperAdministratorException($refusal);
        }
    }

    /**
     * Active super administrators other than the one being changed.
     */
    private function remainingActiveSuperAdministrators(User $target): int
    {
        return User::query()
            ->whereKeyNot($target->getKey())
            ->where('role', Role::SuperAdmin->value)
            ->where('status', UserStatus::Active->value)
            ->count();
    }
}

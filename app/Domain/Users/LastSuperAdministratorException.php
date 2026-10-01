<?php

declare(strict_types=1);

namespace App\Domain\Users;

use RuntimeException;

/**
 * Raised when a change would leave the deployment without a way back in.
 *
 * A distinct exception rather than a validation rule, because the rule is a
 * platform invariant rather than a property of one submitted form, and it must
 * hold no matter which controller performs the mutation.
 */
final class LastSuperAdministratorException extends RuntimeException {}

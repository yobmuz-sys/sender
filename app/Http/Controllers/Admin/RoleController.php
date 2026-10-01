<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Users\Enums\Role;
use App\Domain\Users\Permission;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The role and permission matrix.
 *
 * Read-only, and it stays read-only while the enum is the source of truth. The
 * page exists so an operator can see what each role actually grants, rather
 * than inferring it from the Role enum — but storing permissions anywhere else
 * would immediately create a second answer to "what can this role do", and the
 * two would disagree.
 */
class RoleController extends Controller
{
    public function __invoke(Request $request): View
    {
        abort_unless($request->user()?->can(Permission::USERS_VIEW) ?? false, 403);

        $groups = Permission::groups();

        return view('admin.roles.index', [
            'roles' => Role::cases(),
            'groups' => $groups,
        ]);
    }
}

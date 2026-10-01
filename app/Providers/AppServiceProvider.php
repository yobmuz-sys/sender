<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Users\Permission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Catch lazy loading, missing attributes and discarded mass-assignment
        // input outside production, where the cost of failing loudly is low.
        Model::shouldBeStrict(! $this->app->isProduction());

        $this->registerPermissionGates();
    }

    /**
     * Register one gate per known permission.
     *
     * Registering every permission (instead of only the ones some role holds)
     * means `Gate::has()` is true for the whole catalogue, so a typo in a
     * controller or Blade template fails loudly during testing rather than
     * silently denying access in production.
     */
    private function registerPermissionGates(): void
    {
        foreach (Permission::all() as $permission) {
            Gate::define($permission, static function ($user) use ($permission): bool {
                return $user->role->allows($permission);
            });
        }
    }
}

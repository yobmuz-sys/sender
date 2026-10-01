<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\System\Capabilities\CapabilityRegistry;
use App\Domain\System\Contracts\HostInspector;
use App\Domain\System\Entitlements\DenyAllEntitlement;
use App\Domain\System\Entitlements\Entitlement;
use App\Domain\System\Mail\SmtpCapability;
use App\Domain\System\Runs\RunObserver;
use App\Domain\System\Services\HostCapabilityInspector;
use App\Domain\Users\Permission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Entitlement is deny-by-default until the plans stage replaces this
        // binding. Failing closed means an unentitled feature is unavailable
        // by default rather than by accident.
        $this->app->singleton(Entitlement::class, DenyAllEntitlement::class);

        $this->app->singleton(HostInspector::class, HostCapabilityInspector::class);

        // Capability state is memoised for the life of the request so the
        // report is measured once and every consumer agrees on the answer.
        $this->app->scoped(
            CapabilityRegistry::class,
            fn ($app) => new CapabilityRegistry(
                $app->make(HostInspector::class),
                $app->make(RunObserver::class),
                $app->make(SmtpCapability::class),
            ),
        );
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

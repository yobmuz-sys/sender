<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Extraction\Extractor;
use App\Domain\Extraction\Url\SecureUrlFetcher;
use App\Domain\Mail\DeliveryReadiness;
use App\Domain\Mail\SmtpEndpointPolicy;
use App\Domain\System\Capabilities\CapabilityRegistry;
use App\Domain\System\Contracts\HostInspector;
use App\Domain\System\Entitlements\DenyAllEntitlement;
use App\Domain\System\Entitlements\Entitlement;
use App\Domain\System\Mail\SmtpCapability;
use App\Domain\System\Network\UrlFetchCapability;
use App\Domain\System\Runs\RunObserver;
use App\Domain\System\Services\HostCapabilityInspector;
use App\Domain\Users\Permission;
use App\Support\Navigation\NavigationBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
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

        // The extractor's chunk and batch sizes come from configuration, so it
        // is resolved through the container rather than constructed with
        // integers the container cannot supply.
        $this->app->singleton(Extractor::class, static fn (): Extractor => Extractor::fromConfiguration());

        // Bound rather than autowired: UrlValidator's constructor takes
        // configuration values the container cannot infer, and the fetcher must
        // be the *same* instance everywhere so the capability check and the
        // extraction path cannot drift onto different network policies.
        $this->app->singleton(
            SecureUrlFetcher::class,
            static fn (): SecureUrlFetcher => SecureUrlFetcher::make(),
        );

        // Capability state is memoised for the life of the request so the
        // report is measured once and every consumer agrees on the answer.
        $this->app->scoped(
            CapabilityRegistry::class,
            fn ($app) => new CapabilityRegistry(
                $app->make(HostInspector::class),
                $app->make(RunObserver::class),
                $app->make(SmtpCapability::class),
                $app->make(UrlFetchCapability::class),
            ),
        );

        // A tenant-supplied SMTP host is an outbound network target, exactly as a
        // submitted URL is. Bound as a singleton so the platform SMTP verifier
        // and the per-user verifier are guaranteed to be judging reachability by
        // the same policy — two implementations is two things to weaken later.
        $this->app->singleton(SmtpEndpointPolicy::class, static fn (): SmtpEndpointPolicy => SmtpEndpointPolicy::make());

        // One instance so DNS evidence is resolved once per page and every
        // finding on that page agrees about it.
        $this->app->scoped(DeliveryReadiness::class);
    }

    public function boot(): void
    {
        // Catch lazy loading, missing attributes and discarded mass-assignment
        // input outside production, where the cost of failing loudly is low.
        Model::shouldBeStrict(! $this->app->isProduction());

        $this->registerPermissionGates();

        $this->registerNavigationComposer();
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

    /**
     * Share the current account's navigation with the layout.
     *
     * The layout cannot ask the container for it directly, and injecting it per
     * view would put the same construction in a dozen templates.
     */
    private function registerNavigationComposer(): void
    {
        View::composer('components.layout', NavigationBuilder::class);
    }
}

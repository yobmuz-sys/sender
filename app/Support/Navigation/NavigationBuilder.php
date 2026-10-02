<?php

declare(strict_types=1);

namespace App\Support\Navigation;

use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * Decides which navigation entries the current account may see.
 *
 * Filtering happens here rather than in Blade so the rules stay in one readable
 * place and can be asserted directly, instead of being a dozen `@can` calls
 * scattered through a template.
 *
 * Access is still decided by the Gate layer. This only asks it which entries to
 * draw, so adding an entry without a permission cannot accidentally widen access
 * — an entry with no permission is simply shown to everyone signed in, which is
 * why only the administration dashboard, reachable by any staff role, is
 * declared that way.
 */
final class NavigationBuilder
{
    /**
     * View composer entry point, for the shared layout.
     */
    public function compose(View $view): void
    {
        $view->with([
            'navigation' => $this->build(),
            'pendingRoutes' => $this->pendingRoutes(),
        ]);
    }

    /**
     * @return array{product: list<NavigationItem>, admin: list<NavigationItem>, account: list<NavigationItem>}
     */
    public function build(?object $user = null): array
    {
        $user ??= request()->user();

        if ($user === null) {
            return ['product' => [], 'admin' => [], 'account' => []];
        }

        $granted = static fn (string $permission): bool => $user->can($permission);

        $admin = $this->visible(AdminNavigation::items(), $granted);

        // A role with no permissions at all is a customer account, not staff,
        // and must not be offered an administration surface it cannot use.
        $isStaff = $user->isStaff();

        return [
            'product' => $this->visible(ProductNavigation::items(), $granted),
            'admin' => $isStaff ? $admin : [],
            'account' => ProductNavigation::accountItems(),
        ];
    }

    /**
     * Route names whose page exists only to say a feature is still being built.
     *
     * Read from where the route actually points rather than declared beside the
     * navigation entry, so a section cannot still be labelled as unfinished once
     * the page behind it is real, and cannot silently start claiming to work when
     * it is not. This is presentation only and decides no access whatsoever: the
     * Gate layer already refuses anything a visitor may not open, which is why
     * showing a link is not the same as being able to follow it.
     *
     * @return list<string>
     */
    public function pendingRoutes(): array
    {
        $pending = [];

        $collect = static function (array $items) use (&$collect, &$pending): void {
            foreach ($items as $item) {
                $route = Route::getRoutes()->getByName($item->route);

                if ($route !== null && str_ends_with((string) $route->getActionName(), 'PendingFeatureController')) {
                    $pending[] = $item->route;
                }

                if ($item->children !== []) {
                    $collect($item->children);
                }
            }
        };

        $collect(ProductNavigation::items());
        $collect(AdminNavigation::items());

        return array_values(array_unique($pending));
    }

    /**
     * @param  list<NavigationItem>  $items
     * @param  callable(string): bool  $granted
     * @return list<NavigationItem>
     */
    private function visible(array $items, callable $granted): array
    {
        return array_values(array_map(
            static fn (NavigationItem $item): NavigationItem => new NavigationItem(
                $item->label,
                $item->route,
                $item->permission,
                array_values(array_filter(
                    $item->children,
                    static fn (NavigationItem $child): bool => $child->isVisibleTo($granted),
                )),
                $item->matchPrefix,
                $item->description,
            ),
            array_filter($items, static fn (NavigationItem $item): bool => $item->isVisibleTo($granted)),
        ));
    }
}

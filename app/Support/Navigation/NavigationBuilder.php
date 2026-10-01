<?php

declare(strict_types=1);

namespace App\Support\Navigation;

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
        $view->with('navigation', $this->build());
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

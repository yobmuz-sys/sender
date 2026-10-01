<?php

declare(strict_types=1);

namespace App\Support\Navigation;

/**
 * One entry in a navigation tree.
 *
 * Declared as data rather than written into Blade because a link is exactly the
 * kind of thing that silently rots: a route is renamed, the template keeps
 * compiling, and the only symptom is a link that 404s. Holding the routes in
 * one list lets a test assert every one of them exists.
 */
final readonly class NavigationItem
{
    /**
     * @param  list<NavigationItem>  $children
     */
    public function __construct(
        public string $label,
        public string $route,
        public ?string $permission = null,
        public array $children = [],
        public bool $matchPrefix = false,
        public ?string $description = null,
    ) {}

    public function isVisibleTo(?callable $granted): bool
    {
        // Children can carry a permission the parent does not, so visibility is
        // recursive: a group stays if any of its children can be reached.
        if ($this->children !== []) {
            return $this->visibleChildren($granted) !== [];
        }

        return $granted === null || $this->permission === null || $granted($this->permission);
    }

    /**
     * @return list<NavigationItem>
     */
    public function visibleChildren(?callable $granted): array
    {
        return array_values(array_filter(
            $this->children,
            static fn (NavigationItem $child): bool => $child->isVisibleTo($granted),
        ));
    }

    /**
     * Whether this item should be marked active for the current URL.
     */
    public function isActive(string $path, string $prefix): bool
    {
        return $this->matchPrefix
            ? str_starts_with($path, $prefix)
            : $path === $prefix;
    }
}

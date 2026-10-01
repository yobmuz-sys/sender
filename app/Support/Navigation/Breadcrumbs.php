<?php

declare(strict_types=1);

namespace App\Support\Navigation;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Derives the breadcrumb trail from the current route name.
 *
 * Built from the route rather than declared per view for two reasons. A hand
 * written trail repeats the same prefix on thirty templates, and any of them can
 * disagree with the actual route; and it forces every page to pass a nested
 * array into the layout, which is a lot of noise for something already implied
 * by where the page is.
 *
 * `admin.system.diagnostics` becomes Administration / System / <page title>.
 */
final class Breadcrumbs
{
    /**
     * Route segments that describe an action rather than a page, and so should
     * not appear as a crumb of their own.
     *
     * @var list<string>
     */
    private const ACTIONS = [
        'index', 'show', 'create', 'store', 'edit', 'update',
        'destroy', 'enable', 'disable', 'reset', 'verify', 'send',
        'suspend', 'reinstate', 'forget', 'sendTestMessage',
    ];

    /**
     * Top-level names that do not read well when simply humanised.
     *
     * @var array<string, string>
     */
    private const ROOT_LABELS = [
        'admin' => 'Administration',
        'account' => 'Account',
        'verification' => 'Email confirmation',
        'password' => 'Password reset',
    ];

    /**
     * @return list<array{label: string, route: string|null}>
     */
    public static function for(string $routeName, string $pageTitle, array $parameters = []): array
    {
        $segments = array_values(array_filter(
            explode('.', $routeName),
            static fn (string $segment): bool => $segment !== '' && ! in_array($segment, self::ACTIONS, true),
        ));

        if ($segments === []) {
            return [];
        }

        $crumbs = [];

        foreach ($segments as $index => $segment) {
            $isLast = $index === count($segments) - 1;

            // A parameterised route identifies a specific record, so the page's
            // own title is the accurate final crumb and the segment above it is
            // the collection.
            $label = $index === 0
                ? (self::ROOT_LABELS[$segment] ?? Str::headline($segment))
                : ($isLast && $parameters !== [] ? $pageTitle : Str::headline($segment));

            $crumbs[] = [
                'label' => $label,
                'route' => $isLast ? null : self::parentRoute($segments, $index),
            ];
        }

        return $crumbs;
    }

    /**
     * The route name of the ancestor at the given index, when there is one.
     *
     * @param  list<string>  $segments
     */
    private static function parentRoute(array $segments, int $index): ?string
    {
        $ancestor = array_slice($segments, 0, $index + 1);

        // Trim trailing action names so the link points at the listing page.
        while ($ancestor !== [] && in_array(end($ancestor), self::ACTIONS, true)) {
            array_pop($ancestor);
        }

        if ($ancestor === []) {
            return null;
        }

        $name = implode('.', $ancestor).'.index';

        return Route::has($name) ? $name : null;
    }
}

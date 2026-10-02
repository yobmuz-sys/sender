<?php

declare(strict_types=1);

namespace App\Support\Navigation;

/**
 * Navigation for the signed-in account and the eventual product surface.
 *
 * The product entries exist because navigation completeness is worth more than
 * waiting: a page that says it is pending is honest, whereas a navigation that
 * omits a section makes the platform look less finished than it is. They render
 * a "feature not yet available" state until the domain that backs them exists.
 */
final class ProductNavigation
{
    /**
     * The customer's own product navigation.
     *
     * @return list<NavigationItem>
     */
    public static function items(): array
    {
        return [
            new NavigationItem('Dashboard', 'dashboard'),
            new NavigationItem('Extractor', 'extractor.index', matchPrefix: true),
            // Real pages, unlike the shells below: a tenant's mail accounts and
            // what can be established about them already work. Labelled the way
            // the customer page labels itself, so the two never disagree about
            // what this section is called.
            new NavigationItem('Mail accounts', 'account.smtp.index', matchPrefix: true),
            new NavigationItem('Sending health', 'account.deliverability.index'),
            new NavigationItem('Files', 'files.index', matchPrefix: true),
            // Real as of Stage 5B. A list holds canonical contacts rather than
            // copies, and is the unit a later campaign stage will select from.
            new NavigationItem('Lists', 'lists.index', matchPrefix: true),
            new NavigationItem('Templates', 'templates.index', matchPrefix: true),
            new NavigationItem('Campaigns', 'campaigns.index', matchPrefix: true),
            // Real: addresses this account will never contact, with the reason
            // for each. Every unsubscribe, bounce and complaint lands here and
            // applies to every list, import and future campaign.
            new NavigationItem('Suppression', 'suppression.index'),
            new NavigationItem('Analytics', 'analytics.index'),
        ];
    }

    /**
     * Account pages, which are always available to a signed-in account.
     *
     * @return list<NavigationItem>
     */
    public static function accountItems(): array
    {
        return [
            new NavigationItem('Profile', 'account.profile'),
            new NavigationItem('Security', 'account.security'),
        ];
    }

    /**
     * @return list<string>
     */
    public static function routeNames(): array
    {
        $names = [];

        foreach ([self::items(), self::accountItems()] as $group) {
            foreach ($group as $item) {
                $names[] = $item->route;
            }
        }

        return array_values(array_unique($names));
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeZone;

/**
 * The timezones a customer may schedule against.
 *
 * A scheduled campaign carries an instant, not a wall-clock, so the platform
 * stores UTC and nothing else. What this class exists for is the two ends of
 * that conversion: offering a list whose *current* offset is shown, because
 * "Europe/London" tells a customer nothing about whether they are on winter or
 * summer time the morning they schedule a send, and validating a submitted zone
 * against the same list rather than against PHP's own `timezone_identifiers_list()`,
 * so a value that was never offered cannot arrive in a form post.
 */
final class Timezone
{
    /**
     * Every identifier PHP knows, with the offset it is observing today.
     *
     * @return array<string, string> identifier => label, grouped by region
     */
    public static function options(): array
    {
        $options = [];
        $now = new \DateTimeImmutable('now', new DateTimeZone('UTC'));

        foreach (self::identifiers() as $identifier) {
            $offset = $now->setTimezone(new DateTimeZone($identifier))->format('P');

            $options[$identifier] = sprintf('(GMT%s) %s', $offset, $identifier);
        }

        return $options;
    }

    /**
     * Whether a submitted value is a zone this platform would have offered.
     */
    public static function isValid(string $identifier): bool
    {
        return in_array($identifier, self::identifiers(), true);
    }

    /**
     * The zone used when a customer scheduled nothing, or submitted an empty one.
     *
     * The application's own timezone, which is what "start immediately" is
     * expressed in and what an unscheduled campaign is displayed as.
     */
    public static function default(): string
    {
        $configured = (string) config('app.timezone', 'UTC');

        return self::isValid($configured) ? $configured : 'UTC';
    }

    /**
     * @return list<string>
     */
    private static function identifiers(): array
    {
        $identifiers = DateTimeZone::listIdentifiers();

        // `listIdentifiers()` omits UTC unless it is asked for by name in some
        // builds; naming the pairs keeps the list complete without depending on
        // a specific PHP patch level's grouping.
        return array_values(array_unique([
            ...$identifiers,
            ...DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),
        ]));
    }
}

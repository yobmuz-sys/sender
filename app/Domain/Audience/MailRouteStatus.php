<?php

declare(strict_types=1);

namespace App\Domain\Audience;

/**
 * What DNS says about a domain's ability to receive mail.
 *
 * Four states rather than a boolean, because "there is no MX record" and "the DNS
 * lookup did not complete" are not the same fact and only one of them is
 * evidence. A resolver that is down makes every domain look unroutable, and
 * treating that as evidence would classify an entire valid audience as invalid —
 * which is the single most destructive thing this pipeline could do.
 */
enum MailRouteStatus: string
{
    /**
     * The domain publishes a usable mail route.
     *
     * Either MX records, or the A/AAAA fallback RFC 5321 §5 permits when a
     * domain publishes none. Both are real routes; the fallback is just implicit.
     */
    case HasRoute = 'has_route';

    /**
     * The domain exists in DNS but publishes no mail route of either kind.
     *
     * A definitive, checkable fact: mail addressed to it cannot be delivered
     * because nothing is published to receive it.
     */
    case NoRoute = 'no_route';

    /**
     * The domain does not exist.
     */
    case DomainNotFound = 'domain_not_found';

    /**
     * The resolver did not answer, or answered in a way that cannot be
     * distinguished from non-existence.
     *
     * Never evidence for or against any mailbox. This is the state a DNS outage
     * produces, and it must not be allowed to become `NoRoute`.
     */
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::HasRoute => 'Accepts email',
            self::NoRoute => 'Exists but accepts no email',
            self::DomainNotFound => 'Domain does not exist',
            self::Unavailable => 'Could not be checked',
        };
    }

    /**
     * Whether this status justifies saying an address at the domain cannot
     * receive mail.
     *
     * Only the two definitive states qualify. `Unavailable` is the case that has
     * to be excluded: it is the difference between "your list is bad" and "our
     * resolver was down", and conflating them is how a whole audience gets
     * discarded on a bad afternoon.
     */
    public function isDefinitive(): bool
    {
        return $this === self::NoRoute || $this === self::DomainNotFound;
    }

    /**
     * Whether a mailbox check may be attempted at this domain.
     */
    public function permitsMailboxCheck(): bool
    {
        return $this === self::HasRoute;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Fakes;

use App\Domain\Audience\MailRoute;
use App\Domain\Audience\MailRouteResolver;
use App\Domain\Audience\MailRouteStatus;
use DateTimeInterface;

/**
 * A DNS answer the test chooses, which counts how often it was asked.
 *
 * Two reasons this exists rather than a mock. First, counting: "one MX lookup per
 * window" is a claim about work performed, and it can only be tested by something
 * that knows how many times it was called. Second, honesty about the difference
 * between "this domain publishes no MX" and "our resolver is down" — those are two
 * different statuses, and a test suite that only ever exercised the first would
 * leave the second, which is the destructive one, untested.
 */
final class FakeMailRouteResolver implements MailRouteResolver
{
    /**
     * @var list<string>
     */
    public array $lookups = [];

    /**
     * @param  array<string, MailRouteStatus>  $statuses  Keyed by domain. A
     *                                                    domain with no entry gets `$default`.
     * @param  list<string>  $targets
     */
    public function __construct(
        public array $statuses = [],
        private MailRouteStatus $default = MailRouteStatus::Unavailable,
        private array $targets = ['mx1.mail-host.test'],
    ) {}

    public static function everyDomainHasRoute(): self
    {
        return new self(default: MailRouteStatus::HasRoute);
    }

    public function resolve(string $domain): MailRoute
    {
        $this->lookups[] = $domain;

        $status = $this->statuses[$domain] ?? $this->default;

        return MailRoute::of(
            $domain,
            $status,
            $status === MailRouteStatus::HasRoute ? $this->targets : [],
            $this->now(),
        );
    }

    public function timesResolved(string $domain): int
    {
        return count(array_filter(
            $this->lookups,
            static fn (string $looked): bool => $looked === $domain,
        ));
    }

    protected function now(): DateTimeInterface
    {
        return now();
    }
}

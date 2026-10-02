<?php

declare(strict_types=1);

namespace App\Domain\Audience;

use App\Models\DomainValidationCache as DomainValidationCacheRow;

/**
 * Durable storage for domain-level validation evidence.
 *
 * Domain results — mail routes and catch-all behaviour — are cached in the
 * database rather than the application cache, for two reasons that both matter
 * on shared hosting:
 *
 *   - the cache is shared across processes. The web request that displayed a
 *     domain's status and the worker that classified an address at it must be
 *     looking at the same evidence, and a per-process array would give them
 *     different answers.
 *   - it survives a deployment. A cache the application rebuilds from scratch
 *     on every release is a cache that re-probes every domain after each deploy,
 *     which is precisely the pattern that gets a host's outbound mail blocked.
 *
 * Two TTLs, and the asymmetry is the whole point of separating them:
 *
 *   - mail routes change rarely, and a DNS lookup is cheap for the domain owner
 *     but not free for this host
 *   - catch-all behaviour can be changed by the domain owner without any DNS
 *     change at all, and the answer here is *negative* information — it says
 *     acceptance proves nothing — so a stale answer either discards a usable
 *     audience or, worse, keeps one that no longer exists
 *
 * Neither TTL is long, and neither is a claim about how long DNS is stable.
 */
class DomainValidationCache
{
    /**
     * Read a cached mail route, or resolve and store one.
     */
    public function freshOrResolve(string $domain, MailRouteResolver $resolver): MailRoute
    {
        $cached = $this->freshRoute($domain);

        if ($cached !== null) {
            return $cached;
        }

        $route = $resolver->resolve($domain);

        // Only a resolved route is stored. `Unavailable` is a statement about the
        // resolver at that moment, and caching it would turn a momentary outage
        // into a lasting verdict about every address at the domain.
        if ($route->status !== MailRouteStatus::Unavailable) {
            $this->storeRoute($route);
        }

        return $route;
    }

    /**
     * A mail route that is still current, or null.
     */
    public function freshRoute(string $domain): ?MailRoute
    {
        $row = DomainValidationCacheRow::query()
            ->where('domain', $domain)
            ->whereNotNull('mx_status')
            ->where('expires_at', '>', now())
            ->first();

        if ($row === null) {
            return null;
        }

        return MailRoute::of(
            $domain,
            // The model casts the column, so this is already the enum. The
            // string branch only matters when the cast is absent, and casting a
            // value that is already an enum is a type error rather than a no-op.
            $row->mx_status instanceof MailRouteStatus
                ? $row->mx_status
                : (MailRouteStatus::tryFrom((string) $row->mx_status) ?? MailRouteStatus::Unavailable),
            $this->decodeTargets($row->mx_targets),
            $row->checked_at ?? now(),
        );
    }

    /**
     * The stored catch-all verdict for a domain, or null when none is current.
     */
    public function freshCatchAll(string $domain): ?CatchAllVerdict
    {
        $row = DomainValidationCacheRow::query()
            ->where('domain', $domain)
            ->whereNotNull('catch_all')
            ->where('catch_all_expires_at', '>', now())
            ->first();

        return $row === null ? null : $this->catchAllOf($row->catch_all);
    }

    /**
     * Normalise a stored catch-all verdict, cast or not.
     */
    private function catchAllOf(mixed $value): ?CatchAllVerdict
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof CatchAllVerdict
            ? $value
            : CatchAllVerdict::tryFrom((string) $value);
    }

    /**
     * The catch-all verdict for a domain, probing once if no current answer
     * exists.
     *
     * The single-probe-per-window guarantee lives here rather than in the
     * detector, because the cache is what makes "once" true. A caller that
     * bypassed it would probe on every address and turn a list of ten thousand
     * into ten thousand probes at one mail server.
     */
    public function catchAllVerdict(
        string $domain,
        MailRoute $route,
        CatchAllDetector $detector,
        int $ttlSeconds,
    ): CatchAllVerdict {
        $cached = $this->freshCatchAll($domain);

        if ($cached !== null) {
            return $cached;
        }

        // Only probed where there is somewhere to probe. A domain with no route
        // has already been classified, and a domain whose route is unknown has no
        // host to ask.
        if (! $route->permitsMailboxCheck()) {
            return CatchAllVerdict::Unknown;
        }

        $verdict = $detector->detect($route->targets[0] ?? $domain, $domain);

        // Both answers are cached and only `unknown` is not. Caching the
        // affirmative is what bounds the probe to one per window per domain, and
        // ten thousand addresses at one domain is ten thousand SMTP
        // conversations without it. Caching the negative matters just as much:
        // the same ten thousand addresses would otherwise each probe again, and
        // a domain that has been shown to discriminate has not been shown
        // anything new since.
        //
        // `unknown` is the one answer that must not be remembered. It means the
        // probe did not complete — a timeout, a 252, an unreachable host — and
        // storing it would turn a single unreachable moment into a verdict about
        // every mailbox at the domain for the whole window.
        if ($verdict !== CatchAllVerdict::Unknown) {
            $this->storeCatchAll($domain, $verdict, $ttlSeconds);
        }

        return $verdict;
    }

    /**
     * The probe window is deliberately much shorter than the route window.
     *
     * A catch-all verdict that says "acceptance proves nothing" is the safer of
     * the two to keep, but keeping it for days would exclude a domain whose
     * catch-all has since been switched off, and the customer would have no way
     * to tell why. A few days is long enough that one validation run does not
     * re-probe every domain it touches, and short enough that the exclusion does
     * not silently outlive its cause.
     */
    public function storeCatchAll(string $domain, CatchAllVerdict $verdict, int $ttlSeconds): void
    {
        DomainValidationCacheRow::query()->updateOrCreate(
            ['domain' => $domain],
            [
                'catch_all' => $verdict->value,
                'catch_all_checked_at' => now(),
                'catch_all_expires_at' => now()->addSeconds(max($ttlSeconds, 60)),
            ],
        );
    }

    private function storeRoute(MailRoute $route): void
    {
        DomainValidationCacheRow::query()->updateOrCreate(
            ['domain' => $route->domain],
            [
                'mx_status' => $route->status->value,
                'mx_targets' => $this->encodeTargets($route->targets),
                'checked_at' => $route->observedAt,
                'expires_at' => $route->observedAt->modify('+'.max($this->routeTtlSeconds(), 60).' seconds'),
            ],
        );
    }

    /**
     * @param  list<string>  $targets
     */
    private function encodeTargets(array $targets): ?string
    {
        return $targets === [] ? null : json_encode(array_values($targets));
    }

    /**
     * @return list<string>
     */
    private function decodeTargets(?string $targets): array
    {
        if ($targets === null || $targets === '') {
            return [];
        }

        $decoded = json_decode($targets, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    private function routeTtlSeconds(): int
    {
        return (int) config('sender.validation.domain_cache_ttl_seconds', 21600);
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audience\CatchAllVerdict;
use App\Domain\Audience\MailRouteStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Domain-level validation evidence, shared across workers and deployments.
 *
 * A table rather than a cache entry, for two reasons that both matter on the
 * target host. The worker performing a validation and the web request that later
 * renders the report must be looking at the same evidence, or the two can
 * disagree about whether a domain was ever checked; and the evidence has to
 * survive a deployment, because a cache rebuilt from nothing on every release
 * re-probes every domain it touches after each deploy — which is exactly the
 * pattern that gets a shared host's outbound mail blocked.
 *
 * Deliberately not scoped by tenant. A domain either publishes MX records or it
 * does not, and two accounts looking up the same domain are looking up the same
 * fact about the world. Tenant-scoping this table would double the DNS work for
 * no additional truth.
 *
 * Read and written through
 * {@see \App\Domain\Audience\DomainValidationCache}, which is where the TTLs and
 * the "never cache `unavailable`" rule live.
 */
class DomainValidationCache extends Model
{
    /**
     * One row per domain, so the table is singular. The default pluralizer would
     * guess "domain_validation_caches" and every lookup would miss against a
     * table that migration created.
     */
    protected $table = 'domain_validation_cache';

    protected $fillable = [
        'domain',
        'mx_status',
        'mx_targets',
        'checked_at',
        'expires_at',
        'catch_all',
        'catch_all_checked_at',
        'catch_all_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'mx_status' => MailRouteStatus::class,
            'catch_all' => CatchAllVerdict::class,
            'checked_at' => 'datetime',
            'expires_at' => 'datetime',
            'catch_all_checked_at' => 'datetime',
            'catch_all_expires_at' => 'datetime',
        ];
    }
}

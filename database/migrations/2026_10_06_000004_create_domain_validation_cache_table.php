<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domain-level validation evidence, shared across workers and deployments.
 *
 * A table rather than a cache entry, for two reasons that both matter on the
 * target host. It must be visible to the worker performing a validation *and* to
 * the web request that later renders the report, or the two can disagree about
 * whether a domain was checked; and it must survive a deployment, because a cache
 * the application rebuilds from nothing on every release re-probes every domain
 * it touches after each deploy — which is exactly the pattern that gets a shared
 * host's outbound mail blocked.
 *
 * Mail routes and catch-all verdicts are cached separately, and the difference
 * matters more than it looks:
 *
 *   - a route changes only when DNS changes, so it is cached for hours
 *   - a catch-all verdict is *negative* information — it says acceptance proves
 *     nothing — so a stale one either discards a usable audience or, worse,
 *     keeps one that no longer exists
 *
 * Neither is ever cached as a permanent fact, and `unavailable` is never stored
 * at all: a resolver that did not answer has told us nothing about the domain,
 * and writing that down would turn a momentary outage into a lasting verdict.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_validation_cache', function (Blueprint $table): void {
            $table->id();

            // The domain, lower-cased. The single natural key of the table.
            $table->string('domain');

            /*
             * has_route | no_route | domain_not_found
             *
             * `unavailable` is absent on purpose: it is a statement about the
             * resolver at one moment, not a fact about the domain.
             */
            $table->string('mx_status')->nullable();

            // The MX hosts, or the resolved addresses when the domain relies on
            // the RFC 5321 implicit route. JSON rather than a second table because
            // these are read as a unit and never queried individually.
            $table->text('mx_targets')->nullable();

            $table->timestamp('checked_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            /*
             * The catch-all verdict: yes | no | unknown.
             *
             * Only `yes` is ever written. `no` is the absence of a stored verdict,
             * because a domain that distinguishes real mailboxes is the normal case
             * and needs no row to say so; storing `no` for every domain would be a
             * row per domain for no information. `unknown` is not stored either —
             * it means the probe did not happen or did not answer, and caching that
             * would suppress a probe that might well succeed next time.
             */
            $table->string('catch_all')->nullable();
            $table->timestamp('catch_all_checked_at')->nullable();
            $table->timestamp('catch_all_expires_at')->nullable();

            $table->timestamps();

            $table->unique('domain');
            $table->index('expires_at');
            $table->index('catch_all_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_validation_cache');
    }
};

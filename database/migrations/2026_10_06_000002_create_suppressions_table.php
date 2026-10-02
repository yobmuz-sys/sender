<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-scoped suppression: addresses that must never be contacted again.
 *
 * The property that makes this table matter is that a suppression outlives the
 * thing that created it. An unsubscribe, a hard bounce and a complaint are all
 * recorded once, and from that moment they apply to every list, every future
 * extraction, every re-import and every future campaign for that tenant —
 * without any of those needing to know the suppression exists.
 *
 * That is the failure this table exists to make impossible:
 *
 *     recipient unsubscribes
 *         -> tenant imports the same list again tomorrow
 *             -> tenant sends to them again
 *
 * Re-importing is not a way to undo a recipient's decision. The uniqueness on
 * `contact_id` is what makes that structural: there is one suppression row per
 * contact per tenant, so a second unsubscribe is a no-op rather than a duplicate,
 * and `reason` keeps the *first* recorded cause unless something stronger arrives.
 *
 * There is no cascade to `extraction_results`, because an extraction result is a
 * historical record of what was found and is not a claim that the address may be
 * contacted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppressions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();

            /*
             * unsubscribed | hard_bounce | complaint | manual | admin
             *
             * Stored rather than derived, so the tenant and any operator can see
             * *why* an address is excluded. A suppression with no reason is a
             * black hole, and a list of black holes is impossible to audit.
             */
            $table->string('reason');

            // unsubscribe | bounce_feedback | complaint_feedback | manual |
            // admin. Kept apart from the reason because the same outcome arrives
            // from different places, and "we recorded this because the recipient
            // asked" reads very differently from "we recorded this because a
            // feed told us to".
            $table->string('source')->default('manual');

            // Optional free-text detail from the operator. Never an SMTP response
            // body: those quote addresses.
            $table->string('note')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index('reason');

            // One suppression per contact per tenant. This is the constraint that
            // makes re-importing unable to resurrect a suppressed address.
            $table->unique(['user_id', 'contact_id'], 'suppressions_user_contact_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppressions');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The campaign's own copy of its audience, and its per-recipient state.
 *
 * Deliberately a separate table from `list_contacts`, and not a query over the
 * list at send time. A campaign is a job that runs for hours or days across
 * worker restarts and host reboots; if its audience were "whoever is on the list
 * now", then adding a contact mid-send would put mail on the wire to somebody who
 * was never eligible, and removing one would silently shrink a campaign whose
 * reported totals no longer matched reality. Freezing the audience is what makes
 * pause, resume, retry and restart reliable.
 *
 * The source list is not mutated and excluded contacts are not deleted: a
 * suppressed address stays on the customer's list, because removing it would hide
 * the exclusion in the last place the customer would look for it. They simply do
 * not become a row here, and the count of them is reported on the campaign.
 *
 * `unique(campaign_id, contact_id)` is what makes the snapshot safe to build more
 * than once — on a retried launch, or by two workers racing to launch — so the
 * same person can never receive the same campaign twice because a join was run
 * twice.
 *
 * `next_attempt_at` is the durable scheduling state. It is a timestamp, not a
 * sleep: a worker killed mid-campaign resumes from the database, which is the
 * only reason this survives shared hosting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_recipients', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();

            /*
             * The canonical contact, and a copy of the address.
             *
             * Both, deliberately. The contact is a live row that a customer may
             * delete, and cascading that deletion into this table would rewrite a
             * finished campaign's totals the moment somebody cleaned up a contact:
             * 500 recipients would become 499 for no reason anybody could see. So
             * the reference nulls out and the address stays, because the campaign's
             * own record of who it was going to contact is part of what it froze.
             */
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');

            $table->string('status')->default('queued');

            $table->unsignedSmallInteger('attempts')->default(0);

            // When this recipient may next be attempted. Null means "now".
            $table->timestamp('next_attempt_at')->nullable();

            $table->timestamp('first_attempt_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            // What the receiving server said about the last attempt, so a
            // campaign page can explain a failure without opening every attempt.
            $table->string('provider_message_id')->nullable();
            $table->string('last_error_code')->nullable();
            $table->text('last_error_message')->nullable();

            // When a worker claimed it, so a recipient stuck in `sending` after a
            // killed worker is recoverable rather than lost.
            $table->timestamp('claimed_at')->nullable();

            $table->timestamps();

            // One row per contact, whatever happens during the build.
            $table->unique(['campaign_id', 'contact_id'], 'campaign_recipients_campaign_contact_unique');

            // The claim query's own index: due recipients for one campaign.
            $table->index(['campaign_id', 'status', 'next_attempt_at'], 'campaign_recipients_due_index');

            // Tenant-scoped reads, and the contact's own suppression checks.
            $table->index(['contact_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_recipients');
    }
};

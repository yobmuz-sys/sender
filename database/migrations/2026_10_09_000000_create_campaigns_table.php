<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A campaign: one prepared sending job, frozen at launch.
 *
 * The snapshot columns are the whole reason this table is shaped this way. A
 * campaign does not read its template while it runs; at launch it copies the
 * subject and both bodies out of the template and keeps them here, together with
 * the version it copied. That is what makes editing a template afterwards unable
 * to rewrite a message that is halfway through sending, and it is what makes
 * "which content did this campaign send?" answerable a year later.
 *
 * The references (`template_id`, `list_id`, `smtp_account_id`) are kept, but only
 * as provenance: where this campaign came from, not how it sends. All three null
 * out if the record is deleted, because deleting a template must not destroy a
 * campaign that is already running, and deleting a transport must not either —
 * it must stop the campaign, which the worker does when it finds no transport to
 * send through, and records as a failure rather than silently sending nothing.
 *
 * `next_send_at` is the durable pacing clock. It is a timestamp rather than a
 * sleep, so a worker that is killed mid-run resumes from it instead of restarting
 * a timer, and a host whose scheduler only wakes every few minutes cannot pretend
 * to a precision it does not have.
 *
 * `worker_claimed_at` is the cross-process claim. Two workers must never process
 * the same campaign at once, and the claim is what makes that true on a host
 * where there is no supervisor and no lock file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');

            // Provenance, not authority. See the class note.
            $table->foreignId('template_id')->nullable()->constrained('templates')->nullOnDelete();
            $table->foreignId('list_id')->nullable()->constrained('contact_lists')->nullOnDelete();
            $table->foreignId('smtp_account_id')->nullable()->constrained('smtp_accounts')->nullOnDelete();

            $table->string('status')->default('draft');

            // Scheduling. `scheduled_at` is when the campaign asked to start;
            // `started_at` is when it actually did.
            //
            // `scheduled_at` is stored in UTC like every other timestamp, and
            // `scheduled_timezone` remembers the zone the customer typed it in, so
            // the builder can show them back the clock they chose rather than a UTC
            // wall-clock they never wrote. Without it a London campaign scheduled
            // for 09:00 would send at 09:00 UTC and an hour late.
            $table->timestamp('scheduled_at')->nullable();
            $table->string('scheduled_timezone')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            /*
             * The frozen message, written at launch and never again.
             *
             * Nullable because a draft has not copied anything yet; every
             * launched campaign has all of it, which the launch code asserts
             * before it will change the status.
             *
             * The template's *name* is copied as well as its content. A campaign
             * page has to be able to say which template this was years later, and
             * `template_id` is a live reference with `nullOnDelete` — a deleted
             * template would leave a sent campaign unable to name itself.
             */
            $table->unsignedInteger('template_version')->nullable();
            $table->string('template_name_snapshot')->nullable();
            $table->string('subject_snapshot')->nullable();
            $table->string('preheader_snapshot')->nullable();
            $table->longText('html_body_snapshot')->nullable();
            $table->longText('text_body_snapshot')->nullable();

            // Pacing, as configured by the customer and clamped by policy at use.
            $table->unsignedInteger('rate_interval_seconds')->default(30);
            $table->unsignedInteger('worker_batch_size')->default(10);

            // The durable clock the worker reads to know when it may send again.
            $table->timestamp('next_send_at')->nullable();

            // Cross-process claim, released in a finally.
            $table->timestamp('worker_claimed_at')->nullable();

            // Why it stopped, when it stopped for a reason worth recording.
            $table->string('failure_reason')->nullable();

            // Bumped on every send, pause, resume and completion, so the list and
            // detail pages can order and say "last activity" without scanning
            // delivery attempts on every row.
            $table->timestamp('last_activity_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'next_send_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The validation stage of the extraction pipeline, recorded on the task badge.
 *
 * The extraction record is the user-visible task, and it is still the unit: there
 * is no generic `tasks` table, because a second table to keep in step with this
 * one is a second thing to get wrong, and a task badge that reports from a
 * different source of truth than the work it describes is a badge nobody can
 * trust.
 *
 * What is added is the minimum needed to answer, at a glance and without
 * touching the results table:
 *
 *     how far through checking are we
 *     how many addresses fell into each of the four classifications
 *     when did checking start and finish
 *
 * **No `extracted_count`.** `found_count` already holds the deduplicated number
 * of distinct addresses the extraction produced, and a second column meaning the
 * same thing is a column that will eventually disagree with the first. The report
 * labels it "unique" and the badge labels it "found"; the value is one number
 * read once.
 *
 * The four classification counters are the whole point of the stage and are why
 * the badge can say something useful at a glance. They are denormalised onto the
 * task rather than aggregated on read because the alternative is grouping the
 * entire results table every time somebody opens a list of twenty tasks, and
 * because a report is a record of what this run found — not a live query that
 * changes under the reader as a later validation reclassifies the same contacts.
 *
 * `name` exists because the badge has to say which task this is. Before this,
 * a task was identified only by an incrementing integer, which is a database
 * primary key presented as a human label.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extractions', function (Blueprint $table): void {
            // What the customer called this task. Nullable so an existing row is
            // not given a fabricated name; the report falls back to describing
            // the source.
            $table->string('name')->nullable()->after('user_id');

            /*
             * How many addresses have been through the checker so far.
             *
             * The denominator for the progress bar. It is a count of *work done*,
             * not of addresses found, so a run that is interrupted still shows
             * real progress and can be resumed rather than restarted.
             */
            $table->unsignedBigInteger('validation_processed_count')->default(0);

            // The four classifications, exactly as ValidationStatus names them.
            // Nullable nowhere: all default to zero, because a report must be
            // able to say "zero confirmed invalid" rather than "unknown".
            $table->unsignedBigInteger('confirmed_invalid_count')->default(0);
            $table->unsignedBigInteger('likely_active_count')->default(0);
            $table->unsignedBigInteger('unknown_count')->default(0);
            $table->unsignedBigInteger('risky_count')->default(0);

            // When checking began and ended. Null `validation_completed_at` on a
            // `ready` row is the signal that the list was extracted but never
            // checked, which is a real and common state after the pipeline was
            // introduced and is reported as such rather than as zero findings.
            $table->timestamp('validation_started_at')->nullable();
            $table->timestamp('validation_completed_at')->nullable();

            // The task index. A badge is read far more often than a result row
            // is written, and the report and the task list both scope by tenant
            // and sort by recency.
            $table->index(['user_id', 'created_at'], 'extractions_user_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('extractions', function (Blueprint $table): void {
            $table->dropIndex('extractions_user_created_index');

            $table->dropColumn([
                'name',
                'validation_processed_count',
                'confirmed_invalid_count',
                'likely_active_count',
                'unknown_count',
                'risky_count',
                'validation_started_at',
                'validation_completed_at',
            ]);
        });
    }
};

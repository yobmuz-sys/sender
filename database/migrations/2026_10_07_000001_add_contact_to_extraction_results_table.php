<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provenance, and a task-specific snapshot of what checking found.
 *
 * Two additions, serving two different requirements that must not be collapsed
 * into one column.
 *
 * `contact_id` is provenance. It says which canonical contact this row found, so
 * the same person appearing in ten extractions is one contact with ten edges
 * pointing at it, rather than ten rows that cannot be related to each other. The
 * existing `unique(extraction_id, email)` still governs membership of *this*
 * task, so re-running an extraction converges on the same rows instead of
 * duplicating them.
 *
 * The four `validation_*` columns are the snapshot. The contact holds the latest
 * overall state, which is what eligibility queries read; these hold what this
 * particular task found at the time it found it. That separation is what keeps a
 * report honest: a customer who opens a validation report in March must not see
 * it silently rewrite itself in June because somebody revalidated the same
 * addresses and got different answers. The report is a record of a run, and a
 * record of a run does not change afterwards.
 *
 * `contact_id` is nullable so a row written by the extractor — which knows
 * nothing about contacts — still succeeds, and so the link can be established
 * afterwards in a bounded batch. A foreign key with a null default would be the
 * alternative and would make every insert during extraction depend on a table
 * the extraction stage has no business knowing about.
 *
 * No `normalized_email` here. It is reachable through the contact, and
 * duplicating the key that defines contact identity onto a second table is an
 * invitation to the two disagreeing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extraction_results', function (Blueprint $table): void {
            $table->foreignId('contact_id')->nullable()->constrained()->cascadeOnDelete();

            // The classification this task reached, and the evidence for it.
            // Nullable, because a result is written by the extractor before
            // anything has checked it.
            $table->string('validation_status')->nullable();
            $table->string('validation_reason')->nullable();
            $table->string('validation_method')->nullable();
            $table->timestamp('validated_at')->nullable();

            // Provenance is walked to answer "which tasks found this contact",
            // which is always a tenant-scoped lookup on the contact.
            $table->index(['contact_id', 'extraction_id']);

            // The report filters and groups a task's own results by status, so the
            // index is on the pair rather than on the status alone: a status index
            // with no extraction prefix cannot serve the query that needs it.
            $table->index(['extraction_id', 'validation_status'], 'extraction_results_task_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('extraction_results', function (Blueprint $table): void {
            $table->dropIndex('extraction_results_task_status_index');
            $table->dropIndex(['contact_id', 'extraction_id']);

            $table->dropConstrainedForeignId('contact_id');

            $table->dropColumn([
                'validation_status',
                'validation_reason',
                'validation_method',
                'validated_at',
            ]);
        });
    }
};

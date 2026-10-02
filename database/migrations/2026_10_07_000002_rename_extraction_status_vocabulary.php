<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Move the task vocabulary from extraction-only states to pipeline states.
 *
 * The old values described one stage. The new ones describe a pipeline, and a
 * badge that can only say "processing" is what made the sequencing error in the
 * first stage's design invisible: a single job that extracted ten thousand
 * addresses looked identical, from the outside, to one that was still working.
 *
 *     pending    -> queued
 *     processing -> extracting
 *     completed  -> ready
 *     failed     -> failed      (unchanged)
 *
 * The one judgement call is `completed`. Under the new pipeline an extraction is
 * never `completed` — it finishes extracting and moves to `validating` — so
 * something has to answer. `ready` is correct in the sense that matters
 * operationally: no further work is scheduled for the row. It is *not* a claim
 * that the addresses were checked, and this migration deliberately does not fake
 * that: `validation_processed_count` stays at zero, so the report says the list
 * was extracted and never validated, which is exactly true.
 *
 * A row rewritten to `validating` or a fabricated set of counters would be
 * worse. It would show a customer a progress bar for a check that is not queued
 * anywhere, and the badge would sit at 0% forever with no way to tell that from a
 * run that is genuinely stuck.
 *
 * A separate migration rather than a `Schema::table` in the same file, because
 * this is a data change with a judgement in it and it should be readable on its
 * own months from now.
 */
return new class extends Migration
{
    /**
     * Old value => new value.
     *
     * @var array<string, string>
     */
    private const RENAMES = [
        'pending' => 'queued',
        'processing' => 'extracting',
        'completed' => 'ready',
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $from => $to) {
            DB::table('extractions')->where('status', $from)->update(['status' => $to]);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::RENAMES) as $from => $to) {
            DB::table('extractions')->where('status', $from)->update(['status' => $to]);
        }
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extractions', function (Blueprint $table): void {
            /*
             * Operational state, added once the workload became asynchronous.
             *
             * Without these the only signal that work was happening was a
             * status string, which cannot distinguish "queued for an hour"
             * from "crashed four minutes in". started_at is what an operator
             * reads to decide whether the worker is alive, and completed_at is
             * what a customer reads to know it is worth looking at again.
             */
            $table->timestamp('started_at')->nullable()->after('status');
            $table->timestamp('completed_at')->nullable()->after('started_at');

            /*
             * Counters. found_count is the number of results that exist;
             * processed_count is how many candidates were examined. They are
             * not the same number, and conflating them would hide a run that
             * examined a great deal and found very little.
             */
            $table->unsignedInteger('processed_count')->default(0)->after('found_count');
            $table->unsignedBigInteger('failed_count')->default(0)->after('processed_count');

            // The reason an extraction failed, reduced to something safe to
            // show a customer. Never an exception traceback: see the note on
            // RunRecorder::sanitise() about configuration values in error text.
            $table->text('error')->nullable()->after('failed_count');

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('extractions', function (Blueprint $table): void {
            $table->dropIndex(['status', 'created_at']);
            $table->dropColumn([
                'started_at', 'completed_at', 'processed_count', 'failed_count', 'error',
            ]);
        });
    }
};

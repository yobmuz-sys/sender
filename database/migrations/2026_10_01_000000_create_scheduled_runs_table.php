<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_runs', function (Blueprint $table) {
            $table->id();

            // The command that ran, e.g. 'sender:heartbeat'. Cron health is
            // derived from the presence of real executions, not from a separate
            // heartbeat timestamp that could disagree with them.
            $table->string('command')->index();

            $table->timestamp('started_at')->index();
            $table->timestamp('finished_at')->nullable();

            $table->string('status');

            $table->unsignedInteger('duration_ms')->nullable();

            // Operational counters. Intentionally generic: extraction and SMTP
            // add their own domain tables rather than columns here.
            $table->unsignedInteger('processed_count')->nullable();
            $table->unsignedInteger('failed_count')->nullable();

            // Operational failure detail. Never an exception traceback, which
            // can carry configuration values; see SensitiveData redaction.
            $table->text('error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_runs');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only history of every submission attempt.
 *
 * One row per attempt, never updated, never deleted. The current state of a
 * recipient is `campaign_recipients`, which is mutable on purpose so the worker
 * can claim and finish it cheaply; this table is the part that must survive, and
 * it exists so that later stages answer questions they cannot answer from a
 * mutable status:
 *
 *   - bounce and complaint ingestion needs every attempt, not the last one;
 *   - "why is this recipient failing" needs the sequence of SMTP replies, not one
 *     error message;
 *   - a provider saying one message bounced needs to be matched to the submission
 *     that produced it, which is `provider_message_id`.
 *
 * `attempt_number` is per recipient and starts at 1, so a row is self-describing:
 * this was the third try, and the two before it are here.
 *
 * `result` says what the *server* said, never what happened to the message
 * afterwards. `accepted` means the transport took responsibility for onward
 * delivery and nothing more; it is not a delivery confirmation and must never be
 * reported as one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_attempts', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('campaign_recipient_id')->constrained()->cascadeOnDelete();

            // Per recipient, from 1. Self-describing without a join.
            $table->unsignedSmallInteger('attempt_number');

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            // accepted | temporary_failure | permanent_failure | transport_failure
            // | blocked | skipped
            $table->string('result');

            // The server's own reply, kept because it is the only evidence there
            // is. It is also treated as untrusted text: it comes from a server the
            // platform does not control and is rendered escaped, never as markup.
            $table->string('smtp_code')->nullable();
            $table->text('smtp_response')->nullable();
            $table->string('provider_message_id')->nullable();

            $table->timestamps();

            $table->unique(['campaign_recipient_id', 'attempt_number'], 'delivery_attempts_recipient_attempt_unique');

            // "Show me the failures for this campaign" without a per-recipient
            // scan, which is what a detail page would otherwise do.
            $table->index(['result', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_attempts');
    }
};

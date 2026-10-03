<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One identifier per logical outbound message, rather than one per attempt.
 *
 * `campaign_recipients.message_id` is that identifier. It is generated once, before
 * the first submission, and every attempt for that recipient submits it — so a
 * bounce or complaint arriving in Stage 5D can be traced to the message a person
 * was actually sent, instead of to whichever attempt happened to be running when
 * the server complained.
 *
 * Nullable, because campaigns already launched have recipients with no identifier
 * yet and rewriting them would mean inventing one after the fact for a message that
 * may already have been delivered under a different identifier. The sender fills a
 * missing value in before its first submission, so only recipients that are never
 * attempted keep the null — and those never reached a mail server, so nothing can
 * reference them.
 *
 * `delivery_attempts.message_id` is a copy of the value that attempt actually put
 * on the wire, recorded separately from `provider_message_id`. It is redundant with
 * the recipient's column by construction, and kept deliberately: the attempt row is
 * the audit trail of what happened, and an audit trail that has to be reconstructed
 * by joining is not one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $table): void {
            $table->string('message_id')->nullable()->after('email');
        });

        Schema::table('campaign_recipients', function (Blueprint $table): void {
            // Unique, because two logical messages sharing an identifier would make
            // every future correlation ambiguous rather than wrong: a bounce would
            // resolve to a recipient, just possibly the wrong one, and nothing would
            // ever say so. MySQL permits many nulls in a unique index, so the
            // un-attempted recipients do not collide with each other.
            $table->unique('message_id', 'campaign_recipients_message_id_unique');
        });

        Schema::table('delivery_attempts', function (Blueprint $table): void {
            $table->string('message_id')->nullable()->after('smtp_response');
        });

        Schema::table('delivery_attempts', function (Blueprint $table): void {
            // The lookup Stage 5D will actually run is "every attempt carrying this
            // identifier", which reads one column without touching the recipient.
            $table->index('message_id', 'delivery_attempts_message_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_attempts', function (Blueprint $table): void {
            $table->dropIndex('delivery_attempts_message_id_index');
            $table->dropColumn('message_id');
        });

        Schema::table('campaign_recipients', function (Blueprint $table): void {
            $table->dropUnique('campaign_recipients_message_id_unique');
            $table->dropColumn('message_id');
        });
    }
};

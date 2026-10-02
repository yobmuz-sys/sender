<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recorded evidence that a recipient agreed to be contacted.
 *
 * A table rather than a boolean on the contact, and the difference is the entire
 * point. A boolean answers "may we send?" without ever recording *why*, which
 * makes it impossible to answer the two questions that actually arise: how this
 * recipient came to be on the list, and whether that permission is still good.
 *
 * Consent is also cumulative rather than a single state. A recipient who signed
 * up in 2024 and clicked a confirmation link in 2026 has two records, and the
 * platform keeps both. The contact's *current* status is derived from them; the
 * history is what makes it auditable.
 *
 * `source_reference` and `evidence` are free-form and hold whatever the customer
 * can supply — a form URL, a ticket number, a screenshot filename. They are not
 * verified by this platform and nothing here claims they are: the point is that
 * the customer can point at what they were relying on, not that this platform
 * checked it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_consents', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();

            // web_form | signup | double_opt_in | imported_with_user_attestation |
            // manual
            $table->string('source_type');

            // Whatever the customer can point at: a form URL, a ticket, a file.
            $table->string('source_reference')->nullable();

            // The method recorded alongside the source, so a double opt-in can be
            // distinguished from the same source recorded without confirmation.
            $table->string('method')->default('declared');

            // When permission was given, which is not necessarily when this row was
            // written — an operator may record an agreement from last year today.
            $table->timestamp('granted_at');

            // Set only for a method that proves control of the mailbox. Null here
            // is what makes the record `unknown` rather than `confirmed`, and it is
            // the single field that distinguishes the two.
            $table->timestamp('confirmed_at')->nullable();

            // Revoked rather than deleted: the record that permission once existed
            // is itself part of the history, and a withdrawal has to be
            // distinguishable from never having had permission.
            $table->timestamp('withdrawn_at')->nullable();

            $table->text('evidence')->nullable();

            $table->timestamps();

            // The current status is read on every eligibility query, so the index
            // is on the contact and the recency of the record rather than on every
            // column.
            $table->index(['contact_id', 'withdrawn_at']);
            $table->index('source_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_consents');
    }
};

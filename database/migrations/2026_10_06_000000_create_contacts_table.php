<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The canonical email identity for a tenant.
 *
 * The central change of Stage 5B. Before this, an address existed only as a row
 * belonging to one extraction, so the same person found on ten pages was ten rows
 * with no way to say they were the same person — and therefore no way to record a
 * consent, a suppression, or a validation once and have it apply everywhere.
 *
 * `unique(user_id, normalized_email)` is the invariant the whole audience layer
 * rests on, and it is enforced by the database rather than by an existence check
 * in PHP: a check-then-insert leaves a window between the two in which two
 * workers validating the same address concurrently both see nothing and both
 * insert.
 *
 * The uniqueness is per tenant, not global. The same address may legitimately
 * belong to two accounts — a consultant and the client whose list they are on —
 * and sharing one row between them would leak one customer's consent record and
 * suppression list into another's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table): void {
            $table->id();

            // The tenant. Every read, write and eligibility check is scoped by it.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The address as supplied, and its canonical form. Two columns rather
            // than one because the stored form is what a person recognises in
            // their own list, and lower-casing it in place would make the record
            // look different from what was imported without explaining why.
            $table->string('email');
            $table->string('normalized_email');

            /*
             * The latest overall validation state for this contact.
             *
             * Never null and never assumed: `unknown` with `not_validated` is the
             * state of a contact that has been created but not yet checked, and it
             * is deliberately different from `unknown` with a real reason, because
             * "we never looked" and "we looked and could not tell" are different
             * facts and the report must not conflate them.
             */
            $table->string('validation_status')->default('unknown');
            $table->string('validation_reason')->default('not_validated');
            $table->string('validation_method')->default('none');

            // When the evidence was gathered, and when it stops being current.
            // A mailbox result always expires; there is no permanent state.
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('validation_expires_at')->nullable();

            /*
             * The last SMTP reply codes seen.
             *
             * The code and the enhanced status, and nothing else. A server's
             * response text quotes the address and frequently echoes the rejected
             * identity, so keeping the transcript would mean keeping a copy of the
             * audience in a column that outlives the deployment. Two integers
             * answer every question an operator actually asks.
             */
            $table->unsignedSmallInteger('last_smtp_code')->nullable();
            $table->string('last_enhanced_code', 16)->nullable();

            // Whether the domain accepted a synthetic address during the last
            // probe. Recorded per contact because it is the reason its validation
            // could not be conclusive, and a report that says "unknown" without
            // saying why is not actionable.
            $table->boolean('is_catch_all')->default(false);

            $table->timestamps();

            // The canonical uniqueness. Named so the failure is legible in an
            // error message rather than appearing as a bare duplicate-key.
            $table->unique(['user_id', 'normalized_email'], 'contacts_user_email_unique');
            $table->index('normalized_email');
            $table->index(['user_id', 'validation_status']);
            $table->index(['user_id', 'validation_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};

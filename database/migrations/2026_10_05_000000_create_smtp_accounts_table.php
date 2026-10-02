<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One SMTP transport per row, belonging to one tenant.
 *
 * A single table rather than one for user-managed accounts and another for
 * operator-assigned ones: the transport shape is identical, so two tables would
 * be two things to keep in step and no single column able to answer "is this the
 * operator's to change?".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smtp_accounts', function (Blueprint $table): void {
            $table->id();

            // The tenant this transport belongs to and sends on behalf of. Both
            // administration and ownership checks read this one column.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('label');

            // Defaults and constraints only. The stored host, port and encryption
            // are what is actually used, so a preset is a starting point rather
            // than a behaviour the provider dictates at send time.
            $table->string('provider')->default('custom');

            // user_managed: the tenant may edit it. admin_managed: read-only for
            // the tenant, editable by staff.
            $table->string('management_mode')->default('user_managed');

            $table->string('host');
            $table->unsignedSmallInteger('port');
            $table->string('encryption')->default('starttls');
            $table->string('auth_mode')->default('password');
            $table->string('username')->nullable();

            // Ciphertext, never plaintext. Encryption happens in an Eloquent cast
            // so a raw write cannot bypass it.
            $table->text('secret')->nullable();

            $table->string('from_address');
            $table->string('from_name')->nullable();
            $table->string('reply_to')->nullable();

            // Optional DKIM selector for the sending domain. Absent means DKIM is
            // reported UNKNOWN rather than guessed at.
            $table->string('dkim_selector')->nullable();

            $table->string('status')->default('unverified');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('verification_expires_at')->nullable();

            // A category, never transport text: server responses quote
            // addresses and sometimes the rejected identity.
            $table->string('last_failure_category')->nullable();
            $table->timestamp('last_failure_at')->nullable();

            // Digest of the transport parameters, excluding nothing that would
            // invalidate a verification. Compared on read so a stored READY
            // cannot outlive the configuration it was earned against.
            $table->char('configuration_fingerprint', 64)->nullable();

            $table->timestamps();

            // The administrative list is ordered by owner, then most recently
            // changed; a user's own list is by label.
            $table->index(['user_id', 'label']);
            $table->index('status');
            $table->index('management_mode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smtp_accounts');
    }
};

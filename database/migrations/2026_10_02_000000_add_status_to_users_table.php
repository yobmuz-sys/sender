<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an explicit account status.
 *
 * Suspension is a separate column rather than a special role, because a
 * suspended super administrator is still a super administrator — their
 * authority did not cease to exist, only their ability to exercise it. Encoding
 * that in the role column would lose the distinction and make the audit trail
 * a lie.
 *
 * Existing rows are backfilled to active: an installation upgrading must not
 * lock out every account on the strength of a new column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('status')->default('active')->after('role')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('status');
        });
    }
};

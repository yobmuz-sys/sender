<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opaque, single-purpose links back to a recipient's own record.
 *
 * A table rather than a signed URL, and the reason is specific rather than
 * general caution. A Laravel signed route encodes its subject into the payload
 * and signs it, which makes it tamper-proof but not opaque: the string in a
 * recipient's mailbox contains the record it identifies, in a form anyone can
 * decode, and it travels through web servers, proxies, referrer headers and
 * browser history. For an unsubscribe link — the one URL this platform puts into
 * other people's inboxes — that is a durable, forwardable identifier for
 * "which of your customers is this person", held by every party in the path.
 *
 * So the token is 32 bytes of cryptographic randomness, stored only as a SHA-256
 * digest. The database cannot be used to reconstruct a working link, a leaked
 * backup discloses no link, and the URL identifies nobody.
 *
 * **Why the token is per-recipient and not per-tenant.** A tenant-wide token
 * would be one string copied into every message, and one recipient forwarding a
 * campaign email to a colleague — which happens constantly and is not an attack —
 * would let that colleague unsubscribe their own address and reveal the tenant's
 * entire audience to the platform. Per-recipient tokens make that impossible and
 * make a forwarded link merely a no-op.
 *
 * `contact_id` cascades, so deleting a contact retires its links rather than
 * leaving live tokens pointing at nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unsubscribe_tokens', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();

            /*
             * The SHA-256 digest of the issued token, hex-encoded.
             *
             * Never the token. A digest is enough to match an incoming link and
             * useless for forging one, which is the whole property being bought.
             */
            $table->char('token_hash', 64)->unique('unsubscribe_tokens_hash_unique');

            // When the link was minted, so an operator can tell a link issued for
            // last year's campaign from one issued yesterday.
            $table->timestamp('created_at')->nullable();

            // The address it acts on, for an operator reading the row. Never for
            // the URL.
            $table->index(['contact_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unsubscribe_tokens');
    }
};

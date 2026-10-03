<?php

declare(strict_types=1);

use App\Models\Template;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reusable message content, one row per tenant template.
 *
 * The bodies are stored as the customer wrote them and are never interpreted as
 * application code. A template is a string with a small, fixed set of placeholders
 * in it; it is not a view, not a Blade template, and nothing in this schema
 * invites the platform to compile it.
 *
 * `version` exists because a template is shared but a campaign is not. The moment
 * a campaign starts, it copies the content it will send and records that version,
 * so editing a template afterwards cannot silently rewrite a message somebody
 * already sent — or one that is halfway through sending. Without the counter there
 * is no way to tell "the template changed" from "the campaign was sent with
 * something else".
 *
 * `status` is derived, not declared by the customer: it says whether the row
 * currently holds everything a campaign needs to send it. Nothing sets it by hand,
 * and {@see Template::effectiveStatus()} recomputes it on read so it
 * cannot drift into being wrong.
 *
 * Tenant ownership is enforced by `user_id`, which every query filters on. The
 * index is `(user_id, updated_at)` because the index page is "my templates, most
 * recently changed" and that ordering is the only read the table is sized for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // What the customer calls it. Unique per tenant rather than globally:
            // two accounts must both be able to have an "October update".
            $table->string('name');

            $table->string('subject');

            // The grey line some mail clients show under the subject. Optional
            // because plenty of people do not want one, not because it is unknown.
            $table->string('preheader')->nullable();

            // The customer's own HTML. Rendered inside a sandboxed frame for
            // preview and sent as-is by the campaign stage; never compiled.
            $table->longText('html_body');

            // Required rather than optional: a template with only an HTML body
            // cannot produce a message for a recipient whose client cannot render
            // it, and that recipient would get nothing rather than something plain.
            $table->longText('text_body');

            $table->unsignedInteger('version')->default(1);

            $table->string('status')->default('draft');

            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};

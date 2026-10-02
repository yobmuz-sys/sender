<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Named collections of contacts.
 *
 * A contact belongs to many lists and a list holds many contacts, so this is the
 * many-to-many table — and the pivot holds no contact data of its own. The
 * alternative, copying a contact row per list, would mean an unsubscribe applied
 * to one list had to be discovered and applied to the other copies, and would
 * make "this person is on three lists" a question with no single answer.
 *
 * `unique(list_id, contact_id)` makes duplicate membership impossible at the
 * database rather than by a check first. Adding a contact to a list it is already
 * on is not an error the customer should see, and it must not be a second row.
 *
 * `user_id` is carried on the pivot as well as derivable through the list. That
 * is denormalisation on purpose: the audience queries filter by tenant constantly,
 * and resolving the tenant through a join on every membership row is a cost paid
 * on the largest table in the audience layer for no benefit. The list's own
 * `user_id` remains the authority, and the two are written together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_lists', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');

            // The customer's own words about what this list is for. Not a status
            // field: no list has a lifecycle here, and inventing one would imply
            // a workflow the product does not have.
            $table->text('description')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'name']);
        });

        Schema::create('list_contacts', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('list_id')->constrained('contact_lists')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();

            // Denormalised tenant, written alongside the list's own. See the class
            // note: the audience layer filters on it constantly and the list's
            // column remains the authority.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // When the membership was added, so "newly imported" is answerable.
            $table->timestamp('added_at')->nullable();

            // Duplicate membership is impossible, not merely discouraged.
            $table->unique(['list_id', 'contact_id'], 'list_contacts_list_contact_unique');

            // The list page reads members in the order they were added.
            $table->index(['list_id', 'id']);
            $table->index(['user_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('list_contacts');
        Schema::dropIfExists('contact_lists');
    }
};

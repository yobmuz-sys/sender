<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ContactList;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactList>
 */
class ContactListFactory extends Factory
{
    protected $model = ContactList::class;

    /**
     * A named, empty list.
     *
     * Empty by default because a factory that arrived pre-populated would make
     * every membership test that did not care about membership slower, and would
     * hide a bug where a list gains members it was never given.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => rtrim($this->faker->unique()->words(2, true), '.'),
            'description' => null,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Extraction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Extraction>
 */
class ExtractionFactory extends Factory
{
    protected $model = Extraction::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'source_type' => 'paste',
            'source_ref' => null,
            'content' => "alpha@example.com\n beta@example.com\n",
            'status' => 'completed',
            'found_count' => 2,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'found_count' => 0,
        ]);
    }
}

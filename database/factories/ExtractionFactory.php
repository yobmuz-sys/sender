<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Extraction\ExtractionStatus;
use App\Models\Extraction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Extraction>
 */
class ExtractionFactory extends Factory
{
    protected $model = Extraction::class;

    /**
     * A finished task that was extracted but never checked.
     *
     * `ready` with no validation counters, deliberately rather than a fully
     * validated fixture. It is the shape a list extracted before validation
     * existed really has, and defaulting to it means a test that creates an
     * extraction to assert something about naming or ownership does not
     * accidentally depend on a validation report it never arranged.
     *
     * Tests that care about the classification counts ask for
     * {@see validated()} or set the counters themselves.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'source_type' => 'paste',
            'source_ref' => null,
            'content' => "alpha@example.com\n beta@example.com\n",
            'status' => ExtractionStatus::Ready->value,
            'found_count' => 2,
        ];
    }

    /**
     * A task submitted and not yet picked up.
     */
    public function queued(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ExtractionStatus::Queued->value,
            'found_count' => 0,
            'validation_processed_count' => 0,
            'likely_active_count' => 0,
            'confirmed_invalid_count' => 0,
            'unknown_count' => 0,
            'risky_count' => 0,
        ]);
    }

    /**
     * A task that finished with every address classified.
     *
     * The counters are filled in so the report, the badge and the eligibility
     * query all agree, which is the state the pipeline actually leaves behind.
     */
    public function validated(int $active = 2, int $invalid = 0): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ExtractionStatus::Ready->value,
            'found_count' => $active + $invalid,
            'validation_processed_count' => $active + $invalid,
            'likely_active_count' => $active,
            'confirmed_invalid_count' => $invalid,
            'validation_started_at' => now()->subMinute(),
            'validation_completed_at' => now(),
        ]);
    }
}

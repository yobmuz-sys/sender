<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Extraction\Extractor;
use App\Domain\Users\Enums\Role;
use App\Jobs\ProcessExtractionJob;
use App\Models\Extraction;
use App\Models\User;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ExtractionWorkflowTest extends TestCase
{
    use WithFaker;

    public function test_a_confirmed_user_can_create_and_view_an_extraction_from_pasted_text(): void
    {
        Notification::fake();

        $this->actingAs(User::factory()->role(Role::User)->create())
            ->post('/extractor', [
                'source_type' => 'paste',
                'content' => "alpha@example.com\n beta@example.com\n",
            ])
            ->assertRedirect();

        // The queue runs inline under QUEUE_CONNECTION=sync, so reaching
        // 'completed' here is the assertion that the job is actually dispatched
        // and does work. It previously asserted 'pending', which only held
        // because nothing was ever dispatched.
        $this->assertDatabaseHas('extractions', ['user_id' => auth()->id(), 'status' => 'completed']);
        $this->assertDatabaseCount('extraction_results', 2);

        $extraction = Extraction::query()->first();

        $this->actingAs(auth()->user())
            ->get(route('extractor.show', $extraction))
            ->assertOk();
    }

    public function test_user_cannot_access_another_users_extraction(): void
    {
        $owner = User::factory()->role(Role::User)->create();
        $other = User::factory()->role(Role::User)->create();
        $extraction = Extraction::factory()->for($owner)->create();

        $this->actingAs($other)
            ->get(route('extractor.show', $extraction))
            ->assertNotFound();
    }

    public function test_a_missing_extraction_returns_a_not_found_response(): void
    {
        $this->actingAs(User::factory()->role(Role::User)->create())
            ->get('/extractor/999999')
            ->assertNotFound();
    }

    public function test_a_valid_extraction_detail_page_renders_for_the_owner(): void
    {
        $user = User::factory()->role(Role::User)->create();
        $extraction = Extraction::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('extractor.show', $extraction))
            ->assertOk();
    }

    public function test_a_retry_does_not_duplicate_extraction_results(): void
    {
        $user = User::factory()->role(Role::User)->create();
        $extraction = Extraction::factory()->for($user)->state(['status' => 'pending'])->create();

        $extractor = Extractor::fromConfiguration();

        // Run the same work twice. Results are keyed on (extraction_id, email)
        // with a unique constraint, so a retry converges rather than duplicating.
        (new ProcessExtractionJob($extraction->id))->handle($extractor);
        (new ProcessExtractionJob($extraction->id))->handle($extractor);

        $this->assertSame(2, $extraction->refresh()->found_count);
        $this->assertDatabaseCount('extraction_results', 2);
    }
}

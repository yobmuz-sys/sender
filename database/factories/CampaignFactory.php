<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Campaigns\Campaign;
use App\Domain\Campaigns\CampaignStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    /**
     * A draft campaign with no audience frozen into it.
     *
     * Draft, unconfigured and recipient-less by default, because that is the state
     * a campaign is *created* in and the only one a test can assume without having
     * to undo something. A factory that arrived already launched would make every
     * test that does not care about sending slower, and would hide a bug where a
     * campaign became frozen without a launch.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => rtrim($this->faker->unique()->words(3, true), '.'),
            'status' => CampaignStatus::Draft->value,
            'rate_interval_seconds' => 30,
            'worker_batch_size' => 10,
        ];
    }

    /**
     * A campaign frozen at launch, with a message it will send.
     *
     * The snapshot is filled directly rather than by launching, so a test about
     * sending does not have to satisfy a preflight first. The bodies include the
     * unsubscribe placeholder, because a launched campaign without one is a state
     * the launcher would never produce.
     */
    public function launched(): static
    {
        return $this->state(fn (): array => [
            'status' => CampaignStatus::Running->value,
            'started_at' => now(),
            'next_send_at' => now(),
            'template_version' => 1,
            'subject_snapshot' => 'October update',
            'preheader_snapshot' => 'Three things, in four minutes.',
            'html_body_snapshot' => '<p>Hello {{first_name}}, here is what changed.</p>'
                .'<p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
            'text_body_snapshot' => "Hello {{first_name}}, here is what changed.\n\n"
                .'Unsubscribe: {{unsubscribe_url}}',
        ]);
    }
}

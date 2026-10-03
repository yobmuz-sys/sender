<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Templates\Template;
use App\Domain\Templates\TemplateStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Template>
 */
class TemplateFactory extends Factory
{
    protected $model = Template::class;

    /**
     * A complete, sendable template.
     *
     * Complete by default because the failure this model guards against is a
     * campaign reading a half-finished template: a factory that produced empty
     * bodies would make every test that does not care about readiness slower and
     * would let a bug where a template loses its contents go unnoticed. Tests that
     * want the incomplete case set the fields explicitly, which reads as a
     * deliberate act rather than a default they have to undo.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => rtrim($this->faker->unique()->words(2, true), '.'),
            'subject' => $this->faker->sentence(4),
            'preheader' => $this->faker->sentence(8),
            'html_body' => '<p>Hello {{first_name}},</p><p>'.$this->faker->paragraph().'</p>'
                .'<p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
            'text_body' => "Hello {{first_name}},\n\n".$this->faker->paragraph()."\n\nUnsubscribe: {{unsubscribe_url}}",
            'version' => 1,
            'status' => TemplateStatus::Ready->value,
        ];
    }

    /**
     * A template holding an unrecognised placeholder.
     */
    public function withUnknownPlaceholder(): static
    {
        return $this->state(fn (): array => [
            'html_body' => '<p>Hello {{frist_name}}, sorry for the typo.</p>',
            'status' => TemplateStatus::Draft->value,
        ]);
    }

    /**
     * A template whose contents were never finished.
     */
    public function incomplete(): static
    {
        return $this->state(fn (): array => [
            'text_body' => '',
            'status' => TemplateStatus::Draft->value,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Templates;

/**
 * Whether a template currently holds everything a campaign needs to send it.
 *
 * Derived from the content, never declared by the customer. A template is created
 * complete or not at all — the form requires a subject, an HTML body and a plain
 * text body — so this is not a lifecycle the customer drives; it is the question
 * "could this be sent right now?", answered from the row itself.
 *
 * The distinction that earns its existence is between *ready* and *blocked by a
 * placeholder nobody recognises*. Both look like saved content, and a campaign
 * stage that only checked for empty fields would happily queue the second one and
 * send a recipient a literal `{{frist_name}}`.
 *
 * Only two states, and deliberately no "sent", "paused" or "archived": those belong
 * to a campaign, which is a separate record that will copy this content rather than
 * change its state. A template does not send.
 */
enum TemplateStatus: string
{
    case Draft = 'draft';

    case Ready = 'ready';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Needs attention',
            self::Ready => 'Ready to use',
        };
    }

    /**
     * The reason, in words a customer can act on.
     */
    public function explanation(): string
    {
        return match ($this) {
            self::Draft => 'This template cannot be sent yet. Fix the reasons listed on the template, and it becomes ready.',
            self::Ready => 'This template holds everything a campaign needs. Nothing sends from it until a campaign is started.',
        };
    }
}

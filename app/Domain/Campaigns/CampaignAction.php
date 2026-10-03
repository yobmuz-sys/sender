<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

/**
 * One thing a person can do to a campaign from a page that lists several.
 *
 * The point of naming these is that a destructive action is not rendered like a
 * navigation link. Cancelling stops a campaign for good and cannot be undone, so it
 * is not a button beside "Open" that posts immediately — it is a link to a page
 * that says what will stop and how many messages have already gone out, and only
 * that page has the button. Pausing and resuming are reversible and stay one click.
 *
 * Whether an action is *offered* is not decided here: {@see CampaignSummary::actions()}
 * reads the campaign's own status, so this enum only says how an allowed action
 * looks and where it goes.
 */
enum CampaignAction: string
{
    case Open = 'open';

    case Edit = 'edit';

    case Pause = 'pause';

    case Resume = 'resume';

    case Cancel = 'cancel';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Edit => 'Edit',
            self::Pause => 'Pause',
            self::Resume => 'Resume',
            self::Cancel => 'Cancel campaign',
        };
    }

    public function variant(): string
    {
        return match ($this) {
            self::Open => 'secondary',
            self::Cancel => 'danger',
            default => 'ghost',
        };
    }

    /**
     * Whether the action changes stored state and so needs a POST.
     *
     * False for Cancel as well, and that is not an oversight: cancelling is a POST,
     * but it is made from a confirmation page, so this answers "is it safe to
     * submit from a list" rather than "is it a mutation".
     */
    public function isImmediate(): bool
    {
        return in_array($this, [self::Pause, self::Resume], true);
    }

    /**
     * The route this action posts to, or null when it is a link.
     */
    public function routeName(): ?string
    {
        return match ($this) {
            self::Open => 'campaigns.show',
            self::Edit => 'campaigns.edit',
            self::Pause => 'campaigns.pause',
            self::Resume => 'campaigns.resume',
            self::Cancel => 'campaigns.confirmCancel',
        };
    }
}

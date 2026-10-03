<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use Carbon\CarbonImmutable;

/**
 * One thing that is known to have happened to a campaign, and when.
 *
 * Only recorded facts become entries. There is no event log for campaigns, and
 * adding one purely to draw this list would mean a table that is written to keep
 * a page pretty rather than because anything needs it — so the entries come from
 * the timestamps a campaign already keeps, plus the attempt history, which is a
 * real log and was built for this purpose.
 *
 * What that rules out is stated plainly rather than filled in: pause and resume
 * cycles are not individually recorded (a campaign keeps its current state and the
 * time of the last activity), and a scheduled campaign has no "frozen at" moment
 * because the freeze is not timestamped separately from the launch. Inventing
 * either would give a customer a history that reads like evidence and is not.
 */
final readonly class CampaignTimelineEntry
{
    public function __construct(
        public string $label,
        public ?CarbonImmutable $at,
        public ?string $detail = null,
        public bool $isCurrent = false,
    ) {}
}

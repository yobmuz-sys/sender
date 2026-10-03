<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Operator;

use App\Domain\Campaigns\CampaignStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * How an operator narrows the campaign list.
 *
 * Five filters, because an administrator's question is never only "what campaigns
 * exist" — it is "whose", "using which transport", and "is it moving". Each is
 * either a real value or absent, so a hand-edited URL produces the full list
 * rather than a page that silently matches nothing and reads as "no campaigns".
 *
 * The activity filter is the one that needs justifying, and the honest answer is
 * that it only exists where a timestamp backs it:
 *
 *   - **Moving now** — the campaign has recorded activity within the window. It is
 *     a fact about `last_activity_at`, not a guess.
 *   - **Stalled** — the campaign still claims to be running or waiting while
 *     nothing has happened to it for longer than that window. This is the condition
 *     an operator most wants to find and the one no status column can express,
 *     because the campaign's own status is, correctly, still `running`: it is
 *     waiting for a worker that may never come.
 *   - **Stopped** — paused or failed, which is a state rather than a judgement.
 *
 * There is deliberately no "healthy". Nothing in this platform can compute it, and
 * a filter that implied the absence of unstated problems would be a way of being
 * wrong in a badge.
 */
final readonly class CampaignOperatorFilters
{
    /**
     * How long a campaign may go without recording anything before it counts as
     * stalled.
     *
     * An hour is chosen against the pace the worker can actually achieve: a
     * campaign configured with a minutes-long send interval is *supposed* to be
     * quiet between messages, so a much shorter window would report every
     * deliberately paced campaign as broken.
     */
    public const IDLE_AFTER_MINUTES = 60;

    private function __construct(
        public string $search,
        public string $owner,
        public ?CampaignStatus $status,
        public ?int $smtpAccountId,
        public ?string $activity,
        public ?int $ownerId,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $status = CampaignStatus::tryFrom($request->string('status')->toString());
        $account = $request->integer('smtp_account');
        $owner = $request->integer('owner');

        return new self(
            search: $request->string('search')->trim()->toString(),
            owner: $request->string('owner_search')->trim()->toString(),
            status: $status,
            smtpAccountId: $account > 0 ? $account : null,
            activity: in_array($request->string('activity')->toString(), self::activities(), true)
                ? $request->string('activity')->toString()
                : null,
            ownerId: $owner > 0 ? $owner : null,
        );
    }

    /**
     * The activity filters offered, in the order an operator would use them.
     *
     * @return list<string>
     */
    public static function activities(): array
    {
        return ['moving', 'stalled', 'stopped'];
    }

    /**
     * What an activity filter means, for the dropdown.
     *
     * The labels state what is being measured rather than how the campaign feels.
     * "No recent activity" is a fact about `last_activity_at`; "healthy" would be a
     * judgement this platform has no basis to make.
     */
    public static function activityLabel(string $activity): string
    {
        return match ($activity) {
            'moving' => 'Active in the last hour',
            'stalled' => 'Waiting, but silent for over an hour',
            'stopped' => 'Paused or stopped',
            default => 'Any activity',
        };
    }

    public function isActive(): bool
    {
        return $this->search !== ''
            || $this->owner !== ''
            || $this->status !== null
            || $this->smtpAccountId !== null
            || $this->activity !== null
            || $this->ownerId !== null;
    }

    public function applyTo(Builder $query): void
    {
        if ($this->search !== '') {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $this->search).'%';

            // The name of the campaign, or the frozen subject it is sending. An
            // operator is often given the subject rather than the campaign's name.
            $query->where(static function (Builder $query) use ($term): void {
                $query->where('campaigns.name', 'like', $term)
                    ->orWhere('campaigns.subject_snapshot', 'like', $term);
            });
        }

        if ($this->owner !== '') {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $this->owner).'%';

            $query->where(static function (Builder $query) use ($term): void {
                $query->where('users.name', 'like', $term)
                    ->orWhere('users.email', 'like', $term);
            });
        }

        if ($this->ownerId !== null) {
            $query->where('campaigns.user_id', $this->ownerId);
        }

        if ($this->status !== null) {
            $query->where('campaigns.status', $this->status->value);
        }

        if ($this->smtpAccountId !== null) {
            $query->where('campaigns.smtp_account_id', $this->smtpAccountId);
        }

        if ($this->activity !== null) {
            $this->applyActivity($query);
        }
    }

    /**
     * The activity categories, as conditions on real columns.
     */
    private function applyActivity(Builder $query): void
    {
        $threshold = now()->subMinutes(self::IDLE_AFTER_MINUTES);

        match ($this->activity) {
            'moving' => $query->where('campaigns.last_activity_at', '>=', $threshold),

            // Still waiting to send, but silent for longer than the window. Null
            // counts as stalled: a campaign that has never recorded an activity is
            // the quietest case of all.
            'stalled' => $query->whereIn('campaigns.status', [
                CampaignStatus::Running->value,
                CampaignStatus::Scheduled->value,
            ])->where(static function (Builder $query) use ($threshold): void {
                $query->whereNull('campaigns.last_activity_at')
                    ->orWhere('campaigns.last_activity_at', '<', $threshold);
            }),

            'stopped' => $query->whereIn('campaigns.status', [
                CampaignStatus::Paused->value,
                CampaignStatus::Failed->value,
            ]),

            default => null,
        };
    }
}

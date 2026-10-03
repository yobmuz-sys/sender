<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use App\Domain\Mail\SmtpAccount;
use App\Domain\Templates\Template;
use App\Models\ContactList;
use App\Models\User;
use App\Support\Timezone;
use Carbon\CarbonImmutable;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\DB;

/**
 * One prepared sending job, frozen at launch.
 *
 * The invariant this model exists to protect: **a launched campaign never reads
 * mutable content again.** At launch {@see freeze()} copies the template's content
 * and version into the snapshot columns, and the worker only ever renders from
 * those columns. Editing the template afterwards changes the template and nothing
 * else, which is what makes it safe to edit a template while a campaign built from
 * an earlier version is still going out.
 *
 * The state transitions are methods rather than assignments, because each one has
 * a condition attached that is easy to skip: {@see pause()} refuses a campaign that
 * is not running, {@see resume()} refuses one that was never started, and
 * {@see complete()} only runs when nothing is pending. An assignment would let any
 * of those be bypassed by a controller that was merely in a hurry.
 *
 * Ownership is scoped by the controller; another tenant's campaign is a 404 rather
 * than a 403, because campaign identifiers are sequential and a 403 confirms the
 * record exists.
 *
 * @property CampaignStatus $status
 * @property CarbonImmutable|null $scheduled_at
 * @property string|null $scheduled_timezone
 * @property int|null $template_version
 * @property string|null $template_name_snapshot
 * @property int $rate_interval_seconds
 * @property int $worker_batch_size
 */
class Campaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'template_id',
        'list_id',
        'smtp_account_id',
        'scheduled_at',
        'scheduled_timezone',
        'rate_interval_seconds',
        'worker_batch_size',
    ];

    protected static function newFactory(): CampaignFactory
    {
        return CampaignFactory::new();
    }

    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'next_send_at' => 'datetime',
            'worker_claimed_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'rate_interval_seconds' => 'integer',
            'worker_batch_size' => 'integer',
            'template_version' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The zone this campaign's schedule was written in.
     *
     * Falls back to the application's timezone so a campaign scheduled before
     * this column existed — or one whose form omitted it — still reads as a time
     * the customer recognises rather than as an unexplained UTC clock.
     */
    public function scheduledTimezone(): string
    {
        $zone = (string) ($this->scheduled_timezone ?: '');

        return $zone !== '' && Timezone::isValid($zone) ? $zone : Timezone::default();
    }

    /**
     * The scheduled instant as the customer wrote it, for display.
     *
     * The stored value is UTC and always will be; this is the only place it is
     * converted back, because a schedule shown in a zone its author did not choose
     * is a schedule that looks wrong.
     */
    public function scheduledLocalTime(): ?CarbonImmutable
    {
        if ($this->scheduled_at === null) {
            return null;
        }

        return CarbonImmutable::instance($this->scheduled_at)->setTimezone($this->scheduledTimezone());
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(ContactList::class, 'list_id');
    }

    public function smtpAccount(): BelongsTo
    {
        return $this->belongsTo(SmtpAccount::class, 'smtp_account_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    /**
     * Every submission attempt made for this campaign, across all its recipients.
     *
     * A convenience relation rather than a second place to query: the attempts
     * belong to recipients, and an operations page wants the first and last of them
     * and the most recent refusal without walking the recipient table in PHP.
     *
     * It is for aggregate questions only. A page showing attempt history shows it
     * per recipient, through {@see CampaignRecipient::attempts()}, because that is
     * where the meaning of an attempt number lives.
     */
    public function attempts(): HasManyThrough
    {
        return $this->hasManyThrough(
            DeliveryAttempt::class,
            CampaignRecipient::class,
            'campaign_id',
            'campaign_recipient_id',
        );
    }

    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Whether the launch has frozen this campaign's message and audience.
     */
    public function hasLaunched(): bool
    {
        return $this->template_version !== null && $this->subject_snapshot !== null;
    }

    /**
     * Whether this campaign may still be edited in its configuration.
     */
    public function isConfigurable(): bool
    {
        return $this->status->allowsConfigurationEdit();
    }

    /**
     * Copy the template's content in and mark the campaign started.
     *
     * The snapshot is written here and nowhere else, so there is exactly one place
     * in the codebase where a campaign becomes immutable, and it is the same place
     * that decides whether the content was acceptable.
     *
     * @param  array{name: string, subject: string, preheader: string|null, html_body: string, text_body: string, version: int}  $snapshot
     */
    public function freeze(array $snapshot): void
    {
        $this->template_version = $snapshot['version'];
        $this->template_name_snapshot = $snapshot['name'];
        $this->subject_snapshot = $snapshot['subject'];
        $this->preheader_snapshot = $snapshot['preheader'];
        $this->html_body_snapshot = $snapshot['html_body'];
        $this->text_body_snapshot = $snapshot['text_body'];
    }

    /**
     * Begin sending now.
     */
    public function markRunning(): void
    {
        $this->reloadForTransition();

        $this->forceFill([
            'status' => CampaignStatus::Running->value,
            'started_at' => $this->started_at ?? now(),
            'paused_at' => null,
            'next_send_at' => now(),
            'last_activity_at' => now(),
        ])->save();
    }

    /**
     * Stop sending, at a person's request.
     */
    public function pause(): void
    {
        $this->reloadForTransition();

        if (! $this->status->allowsPause()) {
            return;
        }

        $this->forceFill([
            'status' => CampaignStatus::Paused->value,
            'paused_at' => now(),
            'last_activity_at' => now(),
        ])->save();
    }

    /**
     * Continue from where it stopped.
     *
     * `next_send_at` is reset to now rather than resumed from where it was, so a
     * campaign paused for a week does not send a backlog in the first minute after
     * resuming — which would be the opposite of what a pause is for.
     */
    public function resume(): void
    {
        $this->reloadForTransition();

        if (! $this->status->allowsResume()) {
            return;
        }

        $this->forceFill([
            'status' => CampaignStatus::Running->value,
            'paused_at' => null,
            'next_send_at' => now(),
            'last_activity_at' => now(),
        ])->save();
    }

    /**
     * Stop for good, at a person's request.
     */
    public function cancel(): void
    {
        $this->reloadForTransition();

        if (! $this->status->allowsCancel()) {
            return;
        }

        $this->forceFill([
            'status' => CampaignStatus::Cancelled->value,
            'cancelled_at' => now(),
            'last_activity_at' => now(),
        ])->save();

        // Everything still waiting is given a terminal state now, rather than left
        // queued forever. A cancelled campaign with a hundred queued recipients
        // would make every progress figure on the page a lie.
        $this->recipients()
            ->where('status', CampaignRecipientStatus::Queued->value)
            ->update([
                'status' => CampaignRecipientStatus::Skipped->value,
                'last_error_message' => 'The campaign was cancelled before this message was sent.',
                'updated_at' => now(),
            ]);
    }

    /**
     * Every recipient has been dealt with.
     */
    public function complete(): void
    {
        $this->reloadForTransition();

        $this->forceFill([
            'status' => CampaignStatus::Completed->value,
            'completed_at' => now(),
            'next_send_at' => null,
            'last_activity_at' => now(),
        ])->save();
    }

    /**
     * Stop because something is wrong, not because anybody asked.
     *
     * Used when the transport will not accept mail. There is deliberately no retry
     * here and no attempt to send through a different account: a transport that has
     * stopped working is a thing for a person to fix, and quietly switching to
     * another one would be this platform presenting a slowly failing send as a
     * working one.
     */
    public function markFailed(string $reason): void
    {
        $this->reloadForTransition();

        $this->forceFill([
            'status' => CampaignStatus::Failed->value,
            'failure_reason' => $reason,
            'last_activity_at' => now(),
        ])->save();
    }

    /**
     * Read the row again before deciding to change it.
     *
     * Every transition re-reads first, and the reason is dirty tracking rather than
     * paranoia. The worker writes some of this row's fields through the query
     * builder, so a controller's loaded model can be a few fields behind what is
     * actually stored — and a field whose loaded value happens to match the value
     * being written is not "dirty", so it is left out of the UPDATE. A resume that
     * did not reset the pace clock would then wait out the old interval instead of
     * sending, and nothing would say why.
     *
     * It also makes the state guards honest: a second click of Pause sees the
     * campaign already paused and declines, rather than writing a pause over a
     * campaign that has since completed.
     */
    private function reloadForTransition(): void
    {
        $this->refresh();
    }

    /**
     * Record that something happened to this campaign, for the "last activity"
     * column.
     */
    public function touchActivity(): void
    {
        $this->forceFill(['last_activity_at' => now()])->save();
    }

    /**
     * Take the cross-process claim, or report that somebody else holds it.
     *
     * A conditional update rather than a read-then-write: between the two there is
     * a window in which a second worker would see a free claim and take it. The
     * database decides, atomically, who wins. A claim older than
     * `$staleAfterSeconds` is treated as abandoned — the worker that held it was
     * killed, which on shared hosting is a normal event rather than an exception.
     */
    public function claimForWorker(int $staleAfterSeconds): bool
    {
        $claimed = DB::table('campaigns')
            ->where('id', $this->id)
            ->where(function ($query) use ($staleAfterSeconds): void {
                $query->whereNull('worker_claimed_at')
                    ->orWhere('worker_claimed_at', '<', now()->subSeconds($staleAfterSeconds));
            })
            ->update(['worker_claimed_at' => now()]);

        if ($claimed === 0) {
            return false;
        }

        $this->worker_claimed_at = now();

        // As with a recipient claim: the update went through the query builder, so
        // the model's loaded snapshot has to be brought up to date or a later save
        // would treat the claim as unchanged and omit it.
        $this->syncOriginalAttribute('worker_claimed_at');

        return true;
    }

    public function releaseWorkerClaim(): void
    {
        DB::table('campaigns')
            ->where('id', $this->id)
            ->update(['worker_claimed_at' => null]);

        $this->worker_claimed_at = null;
        $this->syncOriginalAttribute('worker_claimed_at');
    }

    /**
     * Counts by recipient status, in one grouped query.
     *
     * A grouped aggregate rather than six counts: a campaign page renders six
     * figures and a thousand recipients, and six separate queries would be six
     * scans of the same table to produce six integers.
     *
     * @return array<string, int>
     */
    public function recipientCounts(): array
    {
        $counts = $this->recipients()
            ->reorder()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $result = ['total' => 0];

        foreach (CampaignRecipientStatus::cases() as $status) {
            $count = (int) ($counts[$status->value] ?? 0);
            $result[$status->value] = $count;
            $result['total'] += $count;
        }

        return $result;
    }

    /**
     * How many recipients still have to be dealt with.
     */
    public function remainingCount(): int
    {
        return $this->recipients()
            ->whereIn('status', [
                CampaignRecipientStatus::Queued->value,
                CampaignRecipientStatus::Sending->value,
            ])
            ->count();
    }

    /**
     * Whether anything is still waiting to be sent.
     */
    public function hasPendingRecipients(): bool
    {
        return $this->recipients()
            ->where('status', CampaignRecipientStatus::Queued->value)
            ->exists();
    }
}

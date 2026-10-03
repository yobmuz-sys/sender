<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * One recipient of one campaign, and its durable sending state.
 *
 * Every fact the worker needs after a restart lives in this row: whether the
 * message has been sent, how many attempts it has had, when it may next be tried,
 * what the server said last time, and whether a worker currently holds it. Nothing
 * important is kept in a PHP process, because on this kind of host the process
 * ends without warning and the next run has to be able to work out what it was
 * doing.
 *
 * @property CampaignRecipientStatus $status
 * @property int $attempts
 */
class CampaignRecipient extends Model
{
    protected $fillable = [
        'campaign_id',
        'contact_id',
        'email',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => CampaignRecipientStatus::class,
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'first_attempt_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
            'claimed_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(DeliveryAttempt::class);
    }

    /**
     * Recipients a worker may take now.
     *
     * Two populations, not one. A `queued` recipient whose time has come, and a
     * `sending` recipient whose claim has gone stale — a worker that was killed
     * mid-send and will never come back to release it. Without the second, that
     * recipient would be stuck in `sending` for ever: `claim()` can take it, but
     * nothing would ever hand it over. So recovery lives in the query that finds
     * work, which is the only place that can see the claim is abandoned.
     *
     * @param  int  $staleAfterSeconds  How long a claim may be held before another
     *                                  worker assumes its holder is dead.
     */
    public function scopeDue(Builder $query, int $staleAfterSeconds): Builder
    {
        $now = now();

        return $query->where(function (Builder $query) use ($now, $staleAfterSeconds): void {
            $query->where(function (Builder $query) use ($now): void {
                $query->where('status', CampaignRecipientStatus::Queued->value)
                    ->where(function (Builder $query) use ($now): void {
                        $query->whereNull('next_attempt_at')
                            ->orWhere('next_attempt_at', '<=', $now);
                    });
            })->orWhere(function (Builder $query) use ($now, $staleAfterSeconds): void {
                $query->where('status', CampaignRecipientStatus::Sending->value)
                    ->where(function (Builder $query) use ($now, $staleAfterSeconds): void {
                        $query->whereNull('claimed_at')
                            ->orWhere('claimed_at', '<', $now->copy()->subSeconds($staleAfterSeconds));
                    });
            });
        });
    }

    /**
     * Take this recipient for sending, or report that somebody else took it.
     *
     * The whole concurrency argument of the campaign engine is this one line: a
     * conditional update, so the database decides. A worker that reads a row as
     * queued and then writes it is racing every other worker on the host; a worker
     * that updates *where it is still queued* and checks the affected row count has
     * a claim that no second worker can also hold.
     *
     * `sending` rows whose claim has gone stale — a worker killed mid-send — are
     * recoverable rather than lost. They are returned to `queued` here rather than
     * by a separate sweeper, because a claim this old belongs to a process that no
     * longer exists and waiting for a sweep would leave a recipient permanently
     * mid-send.
     */
    public function claim(int $staleAfterSeconds): bool
    {
        $now = now();

        $stale = DB::table('campaign_recipients')
            ->where('id', $this->id)
            ->where(function ($query) use ($staleAfterSeconds, $now): void {
                $query->where('status', CampaignRecipientStatus::Queued->value)
                    ->orWhere(function ($query) use ($staleAfterSeconds, $now): void {
                        $query->where('status', CampaignRecipientStatus::Sending->value)
                            ->where(function ($query) use ($staleAfterSeconds, $now): void {
                                $query->whereNull('claimed_at')
                                    ->orWhere('claimed_at', '<', $now->copy()->subSeconds($staleAfterSeconds));
                            });
                    });
            })
            ->update([
                'status' => CampaignRecipientStatus::Sending->value,
                'claimed_at' => $now,
                'updated_at' => $now,
            ]);

        if ($stale === 0) {
            return false;
        }

        $this->status = CampaignRecipientStatus::Sending;
        $this->claimed_at = $now;

        // The original snapshot is brought up to date as well. The conditional
        // update above went through the query builder, so the model still believes
        // its loaded attributes are what the database holds — and a later save()
        // that sets a field back to those loaded values is not "dirty" and is left
        // out of the UPDATE. Without this, a recipient that failed temporarily
        // would be written back as `sending` with a claim nobody holds, and stay
        // there for ever.
        $this->syncOriginalAttributes(['status', 'claimed_at']);

        return true;
    }

    /**
     * Record a successful submission.
     */
    public function markSent(string $messageId): void
    {
        $this->forceFill([
            'status' => CampaignRecipientStatus::Sent->value,
            'attempts' => ((int) $this->attempts) + 1,
            'first_attempt_at' => $this->first_attempt_at ?? now(),
            'last_attempt_at' => now(),
            'sent_at' => now(),
            'claimed_at' => null,
            'next_attempt_at' => null,
            'provider_message_id' => $messageId,
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();
    }

    /**
     * Record an attempt that did not succeed, and decide whether to come back.
     */
    public function markUnsent(
        AttemptResult $result,
        string $message,
        ?string $code = null,
        ?int $retryInSeconds = null,
        ?string $messageId = null,
    ): void {
        $this->forceFill([
            'status' => $this->statusAfter($result, $retryInSeconds)->value,
            'attempts' => ((int) $this->attempts) + 1,
            'first_attempt_at' => $this->first_attempt_at ?? now(),
            'last_attempt_at' => now(),
            'claimed_at' => null,
            'next_attempt_at' => $retryInSeconds === null ? null : now()->addSeconds($retryInSeconds),
            'last_error_code' => $code,
            'last_error_message' => $message,
            'provider_message_id' => $messageId,
        ])->save();
    }

    /**
     * Record that this recipient was settled without anything being submitted.
     *
     * Distinct from {@see self::markUnsent()} in one way that matters: the attempt
     * counter does not move. A blocked or skipped recipient never reached a mail
     * server, so counting an attempt would burn one of its retries for something
     * that was not a failure — and would make "3 attempts" on a campaign page mean
     * three things depending on how many of those recipients had been unsubscribed
     * by the time their turn came up.
     *
     * The attempt history still records what happened, because "we did not mail
     * them, and here is why" is exactly the record an operator needs.
     */
    public function markNotAttempted(AttemptResult $result, string $message): void
    {
        $this->forceFill([
            'status' => $this->terminalStatusFor($result)->value,
            'next_attempt_at' => null,
            'last_error_message' => $message,
            'claimed_at' => null,
        ])->save();
    }

    /**
     * Where this recipient stands once the attempt is recorded.
     *
     * Retryable is not the same as retried: a temporary failure returns to `queued`
     * only when there is actually a next attempt scheduled. Once the attempt
     * ceiling is reached there is no next attempt, so the recipient becomes
     * `failed` — which is what stops a permanently throttled address cycling
     * through the worker for ever with no delay between tries.
     */
    private function statusAfter(AttemptResult $result, ?int $retryInSeconds): CampaignRecipientStatus
    {
        if ($result->isRetryable() && $retryInSeconds !== null) {
            return CampaignRecipientStatus::Queued;
        }

        return $this->terminalStatusFor($result);
    }

    /**
     * The terminal state a non-retryable outcome leaves behind.
     */
    private function terminalStatusFor(AttemptResult $result): CampaignRecipientStatus
    {
        return match ($result) {
            AttemptResult::Blocked => CampaignRecipientStatus::Blocked,
            AttemptResult::Skipped => CampaignRecipientStatus::Skipped,
            default => CampaignRecipientStatus::Failed,
        };
    }
}

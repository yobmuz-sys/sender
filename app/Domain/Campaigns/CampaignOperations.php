<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use App\Domain\Mail\SmtpAccount;
use App\Support\SensitiveData;
use Carbon\CarbonImmutable;

/**
 * What the platform knows about one campaign, assembled for the operations page.
 *
 * Three ideas, and each of them exists because the alternative was a screen that
 * could not answer a question honestly:
 *
 *   - **Worker visibility is state, not a live feed.** There is no polling and no
 *     countdown. "Next send may happen at 14:32" is the pace clock the worker
 *     reads; "a worker is working on this now" is a non-null claim; "nothing in
 *     the last two hours" is what a stalled campaign looks like, and it is shown
 *     rather than hidden behind an empty panel.
 *   - **Stored server text is scrubbed on the way out, not on the way in.** The
 *     attempt history keeps a server's reply verbatim because that is the only
 *     evidence a future bounce can be matched against. Displaying it is a
 *     different act, so it goes through {@see SensitiveData::redactText()} here —
 *     a connection error routinely quotes the configuration that caused it, and
 *     this page is one a customer may leave on a shared screen.
 *   - **The snapshot is read from the snapshot columns.** Subject, preheader,
 *     version and template name come from the campaign's own copy; a launched
 *     campaign never renders the live template's body, because the whole point of
 *     the freeze is that the live template is no longer what is going out.
 */
final readonly class CampaignOperations
{
    /**
     * @param  list<CampaignTimelineEntry>  $timeline
     */
    public function __construct(
        public Campaign $campaign,
        public CampaignSummary $summary,
        public ?CampaignInterruption $interruption,
        public array $timeline,
        public ?CarbonImmutable $lastSuccessAt,
        public ?DeliveryAttempt $lastFailure,
        public ?CarbonImmutable $nextAttemptAt,
        public ?SmtpAccount $transport,
    ) {}

    /**
     * Read one campaign's operations state.
     *
     * Five queries, and none of them grows with the size of the audience: the
     * timeline needs the first and last attempt and the latest refusal, not the
     * attempt history.
     */
    public static function read(Campaign $campaign, CampaignSummary $summary): self
    {
        $campaign->loadMissing(['list', 'smtpAccount']);

        $recipients = $campaign->recipients();

        $firstAttempt = $campaign->attempts()->min('started_at');
        $lastSuccessAt = $campaign->attempts()
            ->where('result', AttemptResult::Accepted->value)
            ->max('finished_at');

        $lastFailure = $campaign->attempts()
            ->where('result', '!=', AttemptResult::Accepted->value)
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first();

        // The earliest moment any waiting recipient becomes eligible again. Null
        // means nothing is waiting on a timer, which is a different thing from
        // "now" and must not be rendered as an instant.
        $nextAttemptAt = $recipients
            ->where('status', CampaignRecipientStatus::Queued->value)
            ->whereNotNull('next_attempt_at')
            ->min('next_attempt_at');

        $operations = new self(
            campaign: $campaign,
            summary: $summary,
            interruption: CampaignInterruption::for($campaign),
            timeline: self::timelineFor(
                $campaign,
                $firstAttempt === null ? null : CarbonImmutable::parse($firstAttempt),
                $lastSuccessAt === null ? null : CarbonImmutable::parse($lastSuccessAt),
            ),
            lastSuccessAt: $lastSuccessAt === null ? null : CarbonImmutable::parse($lastSuccessAt),
            lastFailure: $lastFailure,
            nextAttemptAt: $nextAttemptAt === null ? null : CarbonImmutable::parse($nextAttemptAt),
            transport: $campaign->smtpAccount,
        );

        return $operations;
    }

    /**
     * The recorded history, oldest first, with the current state marked.
     *
     * @return list<CampaignTimelineEntry>
     */
    private static function timelineFor(
        Campaign $campaign,
        ?CarbonImmutable $firstAttempt,
        ?CarbonImmutable $lastSuccess,
    ): array {
        $entries = [];

        $add = static function (string $label, mixed $at, ?string $detail = null) use (&$entries): void {
            $moment = $at === null ? null : CarbonImmutable::instance(
                $at instanceof \DateTimeInterface ? $at : CarbonImmutable::parse($at),
            );

            if ($moment !== null) {
                $entries[] = new CampaignTimelineEntry($label, $moment, $detail);
            }
        };

        $add('Created', $campaign->created_at);
        $add('Started sending', $campaign->started_at);
        $add('First submission attempted', $firstAttempt);
        $add('Last accepted by a server', $lastSuccess);
        $add('Last activity', $campaign->last_activity_at);

        if ($campaign->paused_at !== null) {
            $add('Paused', $campaign->paused_at);
        }

        if ($campaign->completed_at !== null) {
            $add('Completed', $campaign->completed_at);
        }

        if ($campaign->cancelled_at !== null) {
            $add('Cancelled', $campaign->cancelled_at);
        }

        return $entries;
    }

    /**
     * Whether a worker holds this campaign right now.
     *
     * The claim is taken and released around one bounded pass, so a non-null value
     * means work is genuinely in progress and a null one means it is not — which is
     * worth saying, because "no worker is running" is the single most common
     * explanation for a campaign that looks stuck on a shared host.
     */
    public function workerIsActive(): bool
    {
        return $this->campaign->worker_claimed_at !== null;
    }

    /**
     * The next moment sending is permitted by the pace clock.
     *
     * A floor, not a promise: the worker still has to be woken by the host
     * scheduler, so the copy says so rather than implying a message at that time.
     */
    public function nextSendAt(): ?CarbonImmutable
    {
        $next = $this->campaign->next_send_at;

        if ($next === null || $next->lessThanOrEqualTo(now())) {
            return null;
        }

        return CarbonImmutable::instance($next);
    }

    /**
     * The template name this campaign froze, or null if it has not launched.
     */
    public function templateName(): ?string
    {
        return $this->campaign->template_name_snapshot;
    }

    /**
     * The subject recipients saw, from the snapshot when frozen.
     */
    public function subject(): ?string
    {
        return $this->campaign->hasLaunched()
            ? $this->campaign->subject_snapshot
            : $this->campaign->template?->subject;
    }

    /**
     * The last refusal, with its server text scrubbed for display.
     *
     * @return array{code: string|null, response: string|null, when: string|null}
     */
    public function lastFailureSummary(): array
    {
        $attempt = $this->lastFailure;

        if ($attempt === null) {
            return ['code' => null, 'response' => null, 'when' => null];
        }

        $response = $attempt->smtp_response ?? $attempt->result->explanation();

        return [
            'code' => $attempt->smtp_code,
            'response' => SensitiveData::redactText(trim($response)),
            'when' => $attempt->finished_at?->diffForHumans(),
        ];
    }

    /**
     * Whether this campaign has anything left to send.
     */
    public function hasPendingRecipients(): bool
    {
        return $this->summary->progress->remaining() > 0;
    }

    /**
     * A compact status line for the top of the page.
     *
     * Built from counts and state rather than assembled in the view, so the summary
     * at the top of the screen and the figures in the cards below it are the same
     * numbers read once.
     */
    public function headline(): string
    {
        $progress = $this->summary->progress;

        return match ($this->campaign->status) {
            CampaignStatus::Draft => 'Not started. Nothing has been frozen or sent.',
            CampaignStatus::Scheduled => 'Waiting for its start time, then the worker will begin sending.',
            CampaignStatus::Running => number_format($progress->count(CampaignRecipientStatus::Sent))
                .' accepted by a server, '.number_format($progress->remaining()).' to go.',
            CampaignStatus::Paused => number_format($progress->remaining()).' recipients are waiting, unsent.',
            CampaignStatus::Completed => number_format($progress->total()).' recipients dealt with. Nothing further will be sent.',
            CampaignStatus::Cancelled => 'Cancelled. Nothing further will be sent.',
            CampaignStatus::Failed => 'Stopped. '.number_format($progress->remaining()).' recipients were never contacted.',
        };
    }

    /**
     * What happened to one recipient, safe to display.
     *
     * `last_error_message` holds text from a server or from an exception, and neither
     * is written by this platform. It is the single most likely place on this page to
     * print something that should not be printed — an exception message routinely
     * quotes the connection string that caused it, credentials and all — so it is
     * scrubbed here, at the one place the page reads a recipient's outcome from.
     *
     * @return array{code: string|null, message: string|null}
     */
    public function resultFor(CampaignRecipient $recipient): array
    {
        return [
            'code' => $recipient->last_error_code,
            'message' => $recipient->last_error_message === null
                ? null
                : SensitiveData::redactText($recipient->last_error_message),
        ];
    }

    /**
     * Attempts for one recipient, oldest first, with server text scrubbed.
     *
     * @return list<array{number: int, result: string, code: string|null, response: string|null, at: string|null}>
     */
    public static function attemptsFor(CampaignRecipient $recipient): array
    {
        return $recipient->deliveryAttempts
            ->sortBy('attempt_number')
            ->map(static fn (DeliveryAttempt $attempt): array => [
                'number' => (int) $attempt->attempt_number,
                'result' => $attempt->result->label(),
                'code' => $attempt->smtp_code,
                'response' => $attempt->smtp_response === null
                    ? null
                    : SensitiveData::redactText(trim($attempt->smtp_response)),
                'at' => $attempt->finished_at?->diffForHumans(),
            ])
            ->all();
    }
}

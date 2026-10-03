<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One submission attempt, written once and never changed.
 *
 * Append-only, and that is the only rule about it. The mutable picture of a
 * recipient lives on {@see CampaignRecipient}; this is the part that has to still
 * be there next year, when a provider's bounce webhook arrives naming a message
 * and the only way to know which submission that was is this row.
 *
 * `smtp_response` is text from a server the platform does not control. It is
 * stored verbatim because paraphrasing it would destroy the only evidence there
 * is, and it is rendered escaped everywhere it appears, because it is exactly the
 * kind of string a server could put anything into.
 *
 * @property AttemptResult $result
 * @property int $attempt_number
 * @property string|null $message_id
 */
class DeliveryAttempt extends Model
{
    protected $fillable = [
        'campaign_recipient_id',
        'attempt_number',
        'started_at',
        'finished_at',
        'result',
        'smtp_code',
        'smtp_response',
        'provider_message_id',
        'message_id',
    ];

    protected function casts(): array
    {
        return [
            'result' => AttemptResult::class,
            'attempt_number' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class, 'campaign_recipient_id');
    }
}

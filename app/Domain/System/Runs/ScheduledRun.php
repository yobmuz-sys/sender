<?php

declare(strict_types=1);

namespace App\Domain\System\Runs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One execution of a scheduled command or worker invocation.
 *
 * Exists so cron health is derived from evidence of actual execution rather
 * than from a heartbeat flag that could disagree with what really ran. A
 * timestamp saying "cron is alive" and a record saying "this specific command
 * started, finished, and failed" are different claims, and only the second one
 * can tell an operator why the platform is not doing its work.
 *
 * Deliberately minimal. There is no progress model, no chunking, no dependency
 * graph and no priority. Those belong to the workload that needs them, not to
 * the operational record of a run.
 *
 * @property string $command
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property string $status
 * @property int|null $duration_ms
 * @property int|null $processed_count
 * @property int|null $failed_count
 * @property string|null $error
 */
class ScheduledRun extends Model
{
    /**
     * A run is append-only evidence, not an editable record.
     *
     * `started_at` is when the run began, which is also when the row was
     * created, so a separate `created_at` would duplicate it and an
     * `updated_at` would imply a run can be rewritten after the fact. The only
     * mutation permitted is closing an open run with its outcome.
     */
    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected $fillable = [
        'command',
        'started_at',
        'finished_at',
        'status',
        'duration_ms',
        'processed_count',
        'failed_count',
        'error',
    ];

    public function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'status' => RunStatus::class,
            'duration_ms' => 'integer',
            'processed_count' => 'integer',
            'failed_count' => 'integer',
        ];
    }

    public function succeeded(): bool
    {
        return $this->status === RunStatus::Succeeded;
    }

    /**
     * The most recent execution of a command, or null if it has never run.
     */
    public static function latestFor(string $command): ?self
    {
        return static::query()
            ->where('command', $command)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Extraction\ExtractionStatus;
use Database\Factories\ExtractionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stored pasted-text extraction and its results.
 *
 * The record is the durable unit of work: it is created by the request, picked
 * up by the worker by id, and carries the operational state an operator needs
 * to answer "is my extraction stuck". See ExtractionStatus for the lifecycle.
 */
class Extraction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'source_type',
        'source_ref',
        'content',
        'status',
        'found_count',
        'processed_count',
        'failed_count',
        'validation_processed_count',
        'confirmed_invalid_count',
        'likely_active_count',
        'unknown_count',
        'risky_count',
        'error',
    ];

    protected $attributes = [
        'status' => ExtractionStatus::Queued->value,
        'found_count' => 0,
        'processed_count' => 0,
        'failed_count' => 0,
        'validation_processed_count' => 0,
        'confirmed_invalid_count' => 0,
        'likely_active_count' => 0,
        'unknown_count' => 0,
        'risky_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => ExtractionStatus::class,
            'found_count' => 'integer',
            'processed_count' => 'integer',
            'failed_count' => 'integer',
            'validation_processed_count' => 'integer',
            'confirmed_invalid_count' => 'integer',
            'likely_active_count' => 'integer',
            'unknown_count' => 'integer',
            'risky_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'validation_started_at' => 'datetime',
            'validation_completed_at' => 'datetime',
        ];
    }

    /**
     * The pasted content is never serialised into a log line, an array dump or
     * a queue payload. It can be hundreds of kilobytes of third-party text, and
     * it is the payload, not metadata.
     */
    protected $hidden = [
        'content',
    ];

    protected static function newFactory(): ExtractionFactory
    {
        return ExtractionFactory::new();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(ExtractionResult::class);
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * What this task should be called on a badge.
     *
     * The name the customer gave it, or a description of where it came from. An
     * incrementing integer is a database primary key and is not a label a person
     * can recognise — "task 41" tells them nothing they can act on, and the
     * source reference is the one thing on the row they will recognise.
     */
    public function displayName(): string
    {
        if ($this->name !== null && trim($this->name) !== '') {
            return trim($this->name);
        }

        return $this->source_type === 'url'
            ? (string) ($this->source_ref ?: 'A webpage')
            : 'Pasted text';
    }
}

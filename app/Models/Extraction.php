<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ExtractionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Extraction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'source_type',
        'source_ref',
        'content',
        'status',
        'found_count',
    ];

    protected $attributes = [
        'status' => 'pending',
        'found_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'found_count' => 'integer',
        ];
    }

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
}

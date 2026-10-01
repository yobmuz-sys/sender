<?php

declare(strict_types=1);

namespace App\Domain\System\Settings;

use Illuminate\Database\Eloquent\Model;

/**
 * A single operator-controlled setting.
 *
 * Exists because an emergency stop must survive `php artisan cache:clear`.
 * Storing kill switches in the cache would silently re-enable a subsystem that
 * an operator had deliberately disabled.
 *
 * @property string $key
 * @property string|null $value
 */
class SystemSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    public function casts(): array
    {
        // JSON rather than 'array': subsystem flags store plain booleans, and
        // an array cast would turn false into [false], which reads back as true.
        return [
            'value' => 'json',
        ];
    }
}

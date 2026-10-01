<?php

declare(strict_types=1);

namespace App\Domain\System\Settings;

/**
 * Typed access to operator-controlled settings.
 *
 * Deliberately narrow: this is not a general configuration layer and it does
 * not read or write .env. Only settings the operator must be able to change
 * while the application is running belong here.
 *
 * Lookups go through the `key` column, never through find(): the primary key
 * is the auto-increment `id`, and whereKey() would coerce a settings key to 0
 * and silently match nothing.
 */
final class SystemSettings
{
    public function get(string $key, mixed $default = null): mixed
    {
        $setting = SystemSetting::query()->where('key', $key)->first();

        return $setting === null ? $default : $setting->value;
    }

    public function set(string $key, mixed $value): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value],
        );
    }

    public function has(string $key): bool
    {
        return SystemSetting::query()->where('key', $key)->exists();
    }

    public function forget(string $key): void
    {
        SystemSetting::query()->where('key', $key)->delete();
    }
}

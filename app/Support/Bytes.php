<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Byte value helpers shared by configuration and diagnostics.
 */
final class Bytes
{
    /**
     * Convert a PHP ini shorthand value ("512M", "1G", "-1", "") to bytes.
     *
     * A negative value means "unlimited" and is preserved as-is.
     */
    public static function fromIni(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * Render a byte count for human consumption, e.g. "512M".
     */
    public static function humanize(int $bytes): string
    {
        if ($bytes < 0) {
            return 'unlimited';
        }

        $units = ['B', 'K', 'M', 'G', 'T'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), 1).$units[$power];
    }
}

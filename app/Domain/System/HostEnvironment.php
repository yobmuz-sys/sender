<?php

declare(strict_types=1);

namespace App\Domain\System;

use App\Support\Bytes;

/**
 * A snapshot of the PHP runtime limits the platform depends on.
 *
 * Reading these through a value object rather than calling `ini_get()`
 * directly keeps the capability inspector testable: the web SAPI and the CLI
 * SAPI report different values, and a test cannot change the SAPI it runs in.
 */
final readonly class HostEnvironment
{
    public function __construct(
        public string $phpVersion,
        public string $memoryLimit,
        public int $maxExecutionTimeSeconds,
        public int $maxUploadBytes,
        public int $maxPostBytes,
    ) {}

    public static function current(): self
    {
        return new self(
            phpVersion: PHP_VERSION,
            memoryLimit: (string) ini_get('memory_limit'),
            // 0 means "unlimited", which is the normal value under the CLI SAPI.
            maxExecutionTimeSeconds: (int) ini_get('max_execution_time'),
            maxUploadBytes: Bytes::fromIni((string) ini_get('upload_max_filesize')),
            maxPostBytes: Bytes::fromIni((string) ini_get('post_max_size')),
        );
    }
}

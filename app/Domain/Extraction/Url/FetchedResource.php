<?php

declare(strict_types=1);

namespace App\Domain\Extraction\Url;

/**
 * A response that passed every check, held in a temporary file.
 *
 * The body is on disk, not in memory and not in the database. That is what
 * keeps a hostile server from filling either: the ceiling applies while writing,
 * and only the file handle crosses into the extraction stage.
 *
 * The caller owns the file and must delete it. Nothing here registers a
 * shutdown hook, because a shutdown hook that silently deletes files turns a
 * missing cleanup into an invisible one.
 */
final class FetchedResource
{
    public function __construct(
        public readonly string $path,
        public readonly int $bytes,
        public readonly string $contentType,
        public readonly string $finalUrl,
    ) {}

    public function remove(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}

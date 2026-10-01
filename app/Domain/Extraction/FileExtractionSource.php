<?php

declare(strict_types=1);

namespace App\Domain\Extraction;

/**
 * An extraction whose bytes are on disk.
 *
 * This is the URL workload's source. It exists so the fetch can be genuinely
 * streamed: the response is written to a temporary file as it arrives, and the
 * extractor then walks that file a chunk at a time. Neither the download nor
 * the extraction ever holds the whole body in memory, so the response ceiling
 * bounds disk rather than being a claim about memory that cannot be kept.
 *
 * The counterpart to {@see DatabaseExtractionSource}, which reads a persisted
 * `longText`. That one still loads its content before slicing, because it is
 * bounded by an input ceiling enforced at submission; this one does not, because
 * it can be handed a body of whatever size arrived.
 *
 * The file is owned by the caller. Closing without deleting would leak a
 * temporary file per fetch, so both happen together in `dispose()`.
 */
final class FileExtractionSource implements ExtractionSource
{
    /**
     * @var resource|null
     */
    private $handle;

    /**
     * @param  string  $path  Owned by this object: `dispose()` removes it.
     */
    public function __construct(private readonly string $path) {}

    public function size(): int
    {
        clearstatcache(true, $this->path);

        return is_file($this->path) ? (int) filesize($this->path) : 0;
    }

    public function chunks(int $chunkBytes, callable $consume): void
    {
        $handle = $this->open();

        if ($handle === null || $chunkBytes < 1) {
            return;
        }

        // Overlap between chunks so an address spanning a boundary is still
        // matched whole in at least one chunk. Rewound each pass so the method
        // is safe to call more than once.
        $step = max(1, $chunkBytes - DatabaseExtractionSource::OVERLAP_BYTES);
        $offset = 0;
        $total = $this->size();

        while ($offset < $total) {
            fseek($handle, $offset);
            $chunk = fread($handle, $chunkBytes);

            if ($chunk === false || $chunk === '') {
                return;
            }

            $consume($chunk);

            if ($offset + $chunkBytes >= $total) {
                return;
            }

            $offset += $step;
        }
    }

    /**
     * Close the handle and remove the file.
     */
    public function dispose(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }

        $this->handle = null;

        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    /**
     * @return resource|null
     */
    private function open()
    {
        if ($this->handle !== null) {
            return $this->handle;
        }

        if (! is_readable($this->path)) {
            return null;
        }

        $handle = fopen($this->path, 'rb');

        return $handle === false ? null : $this->handle = $handle;
    }
}

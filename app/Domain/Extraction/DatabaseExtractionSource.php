<?php

declare(strict_types=1);

namespace App\Domain\Extraction;

/**
 * An extraction whose content is a string in the database.
 *
 * The content is read once, then walked in bounded slices. That is a genuine
 * improvement over `preg_match_all` over the whole string — candidate arrays
 * and batch arrays are now bounded regardless of input size — but it is worth
 * being precise about what it does and does not do: the underlying content is
 * still a single `longText` value in memory, bounded by the byte ceiling
 * enforced at submission.
 *
 * Keeping it this way is what makes the source swappable later. A filesystem or
 * streamed-HTTP source can hold the same ceiling without this class changing,
 * because the consumer only ever sees `chunks()`.
 */
final class DatabaseExtractionSource implements ExtractionSource
{
    public function __construct(private readonly string $content) {}

    public function size(): int
    {
        return strlen($this->content);
    }

    public function chunks(int $chunkBytes, callable $consume): void
    {
        if ($this->content === '' || $chunkBytes < 1) {
            return;
        }

        $total = strlen($this->content);
        $offset = 0;

        while ($offset < $total) {
            $consume(substr($this->content, $offset, $chunkBytes));

            if ($offset + $chunkBytes >= $total) {
                return;
            }

            // Step back far enough that an address spanning the boundary is
            // present whole in at least one chunk. 320 bytes is comfortably
            // longer than the maximum length of an address the pattern accepts
            // (RFC 5321 caps a path at 256 octets).
            $offset += max(1, $chunkBytes - self::OVERLAP_BYTES);
        }
    }

    /**
     * Bytes of overlap retained between chunks.
     */
    public const OVERLAP_BYTES = 320;
}

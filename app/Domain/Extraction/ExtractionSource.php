<?php

declare(strict_types=1);

namespace App\Domain\Extraction;

/**
 * Reads a stored extraction as a bounded sequence of overlapping chunks.
 *
 * The workload used to load the whole content, run one `preg_match_all` over
 * it, and hold every match in memory before writing anything. That is fine at
 * twenty kilobytes and wrong at a megabyte: peak memory grows with the input
 * and with the number of candidates, so the two limits that are supposed to
 * bound the workload were both unbounded in practice.
 *
 * Chunks overlap by the length of the longest address that could plausibly
 * straddle a boundary, so an address split across two chunks is still matched.
 * Without the carry the last few characters of one chunk and the first of the
 * next would be silently lost, which is the kind of defect that only shows up
 * on inputs nobody tests.
 *
 * Deliberately an interface over "where the bytes come from" rather than a
 * general storage subsystem. Today the only source is the `content` column;
 * when uploaded files or URL responses arrive, they implement the same
 * contract and the extraction algorithm does not change.
 */
interface ExtractionSource
{
    /**
     * Total bytes available, when known.
     */
    public function size(): int;

    /**
     * Yield the source in chunks, each at most `$chunkBytes` long except for
     * the final one, with overlap between consecutive chunks.
     *
     * @param  callable(string): void  $consume
     */
    public function chunks(int $chunkBytes, callable $consume): void;
}

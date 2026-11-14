<?php

declare(strict_types=1);

namespace Charm\Zstd;

use Charm\Zstd\Internal\Engine;

/**
 * A userspace Zstandard (RFC 8878) codec in plain PHP: the one-shot functions. For input that arrives or leaves in pieces see
 * Decoder and Encoder.
 *
 * decompress() reads any frame the reference compressor writes without a dictionary: raw, RLE and compressed blocks, raw, RLE,
 * Huffman and treeless literals (1 or 4 streams), predefined, RLE, FSE and repeat sequence tables, content checksums (verified),
 * concatenated and skippable frames. compress() writes single-segment frames: a hash-table match finder, Huffman-coded literals and
 * FSE-compressed (or predefined or RLE) sequence tables chosen per block by size; its output is valid for every decoder.
 */
final class Zstd
{
    /**
     * Compresses a binary string into a valid RFC 8878 Zstandard (.zst) frame: single segment, the content size in the header, no
     * checksum (smaller for small inputs). Finds matches with a hash table of 16 384 entries keyed by 4 bytes (kept between calls:
     * entries are only candidates and are verified before use), checks the most recent offset (repeat offset 1) before the table, writes
     * the literals as raw, RLE or Huffman-coded (one or four streams) and each kind of sequence code with a predefined, RLE or
     * FSE-compressed table, whichever is smaller. Falls back to raw or RLE blocks when that is smaller.
     */
    public static function compress(string $data): string
    {
        return Engine::compress($data);
    }

    /**
     * Decompresses Zstandard data (one frame, or several concatenated; skippable frames are skipped) into the original bytes.
     *
     * @param int|null $maxSize stop with a \UnexpectedValueException as soon as the output would exceed this many bytes. The check is made
     *                          once per block (at most 128 KiB of output), so at most one block more than $maxSize is produced
     *                          before it throws; a frame header that declares more than the limit is rejected before decoding.
     *
     * @throws \UnexpectedValueException for every failure caused by the input: not a Zstandard frame, corrupt or truncated data, a checksum
     *                         mismatch, a frame that needs a dictionary, or output above $maxSize
     */
    public static function decompress(string $data, ?int $maxSize = null): string
    {
        return Engine::decompress($data, $maxSize);
    }
}

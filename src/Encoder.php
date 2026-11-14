<?php

declare(strict_types=1);

namespace Charm\Zstd;

use Charm\Zstd\Internal\{Engine, EncoderState};

/**
 * Incremental Zstandard compressor. Feed it input in chunks of any size; it returns the compressed bytes that became final.
 * The output is one frame with a content checksum and no content size (so the input need not be known in advance).
 *
 *     $encoder = new Encoder();
 *     $out = fopen('data.zst', 'wb');
 *     while (!feof($in)) {
 *         fwrite($out, $encoder->encode(fread($in, 65536)));
 *     }
 *     fwrite($out, $encoder->finish());
 *
 * Semantics:
 *  - The input is cut into blocks of exactly 128 KiB, each compressed as soon as it is complete (the last block holds what is left).
 *    So without flush() the output depends only on the input, never on how it was cut into chunks, and encode() returns '' until a
 *    block is complete. At most one block of input (plus a 128 KiB window of history) is held.
 *  - The frame header declares a 128 KiB window, which is the farthest back the match finder reaches; every block is at most 128 KiB.
 *  - flush() ends the current block early (not the stream), so a Decoder that was given everything returned so far has all the input
 *    given so far. A flushed block is smaller than 128 KiB: flush at every chunk costs ratio.
 *  - finish() ends the stream (last block, checksum) and resets the encoder so it can encode another stream.
 *  - The match finder and the entropy coding are those of Zstd::compress(); the stream is valid for every Zstandard decoder.
 */
final class Encoder
{
    /** Magic number, descriptor (no content size, checksum, window descriptor present) and window descriptor: 2^17 = 128 KiB. */
    private const HEADER = "\x28\xB5\x2F\xFD\x04\x38";
    private const BLOCK = 131072;
    /** The history kept before the pending input is the window; it is cut to that once this much more has been compressed. */
    private const TRIM = 1 << 21;

    /** The window (already compressed bytes that later blocks may match) followed by the pending input. */
    private string $buf = '';
    /** Where the pending input starts in $buf. */
    private int $done = 0;
    private bool $started = false;
    private EncoderState $es;
    private \HashContext $hash;

    public function __construct()
    {
        $this->reset();
    }

    /** Adds input and returns the compressed bytes that became final (possibly ''). */
    public function encode(string $chunk): string
    {
        if ($chunk === '') {
            return '';
        }
        \hash_update($this->hash, $chunk);
        $this->buf .= $chunk;
        if (\strlen($this->buf) - $this->done < self::BLOCK) {
            return '';
        }
        $out = [];
        while (\strlen($this->buf) - $this->done >= self::BLOCK) {
            $out[] = Engine::encodeBlock($this->buf, $this->done, self::BLOCK, 0, $this->es);
            $this->done += self::BLOCK;
            if ($this->done >= self::BLOCK + self::TRIM) {
                $this->trim();
            }
        }
        return $this->header() . \implode('', $out);
    }

    /**
     * Returns compressed bytes such that a Decoder fed everything returned so far outputs all input given so far, without ending the
     * stream: the pending input becomes a block of its own. Calling it with nothing pending returns ''.
     */
    public function flush(): string
    {
        $n = \strlen($this->buf) - $this->done;
        if ($n === 0) {
            return '';
        }
        $block = Engine::encodeBlock($this->buf, $this->done, $n, 0, $this->es);
        $this->done += $n;
        return $this->header() . $block;
    }

    /** Ends the stream and returns its remaining bytes. Resets the encoder, so it can encode another stream. */
    public function finish(): string
    {
        $n = \strlen($this->buf) - $this->done;
        // (nothing pending: the last block is an empty raw block)
        $block = $n === 0 ? "\x01\x00\x00" : Engine::encodeBlock($this->buf, $this->done, $n, 1, $this->es);
        $out = $this->header() . $block . \substr(\strrev(\hash_final($this->hash, true)), 0, 4);
        $this->reset();
        return $out;
    }

    private function reset(): void
    {
        $this->buf = '';
        $this->done = 0;
        $this->started = false;
        $this->es = new EncoderState();
        $this->es->hash = \array_fill(0, 16384, -1);
        $this->hash = \hash_init('xxh64');
    }

    /** The frame header, once, with the first block. */
    private function header(): string
    {
        if ($this->started) {
            return '';
        }
        $this->started = true;
        return self::HEADER;
    }

    /** Drops the input before the window. The hash table's positions move with it (those that fall out become negative: never matched). */
    private function trim(): void
    {
        $cut = $this->done - self::BLOCK;
        $this->buf = \substr($this->buf, $cut);
        $this->done -= $cut;
        $this->es->hash = \array_map(static fn (int $p): int => $p - $cut, $this->es->hash);
    }
}

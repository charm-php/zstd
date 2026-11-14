<?php

declare(strict_types=1);

namespace Charm\Zstd;

use Charm\Zstd\Internal\{Engine, FrameState};

/**
 * Incremental Zstandard decompressor. Feed it input in chunks of any size and any alignment (a chunk may end anywhere, in a block
 * header or in the middle of a block); it returns the output that became available. Where the chunks are cut never changes the result.
 *
 *     $decoder = new Decoder();
 *     $h = fopen('data.zst', 'rb');
 *     while (!feof($h)) {
 *         echo $decoder->decode(fread($h, 65536));
 *     }
 *     echo $decoder->finish();
 *
 * Semantics:
 *  - Accepts what Zstd::decompress() accepts, and rejects what it rejects: one frame or several concatenated, skippable frames
 *    (skipped without being held), content checksums verified, no dictionaries, nothing after the last frame but another frame.
 *  - Output arrives a block at a time (at most 128 KiB): input is held until a whole block is present, then the block is decoded and
 *    its output returned. A chunk that does not complete a block returns ''.
 *  - Memory: the input of the block being received and the window of the frame (the Window_Size of its header, or its content size
 *    for a single-segment frame; matches never reach back further), not the whole output. The history is trimmed in large steps.
 *  - $maxSize counts the output of the whole stream, all frames. A frame whose header declares more than what is left of it is
 *    refused before anything is decoded; otherwise the check is made once per block, so at most one block (128 KiB) more than
 *    $maxSize is produced before \UnexpectedValueException is thrown.
 *  - finish() ends the input: it throws \UnexpectedValueException if the stream is incomplete (a frame that is not finished, a partial
 *    frame header, no input at all), and resets the decoder so that it can decode another stream.
 *  - A \UnexpectedValueException is thrown by decode() as soon as the bad input is seen, or by finish() for input that ended too soon. After
 *    it the decoder is in an undefined state: discard it.
 */
final class Decoder
{
    private const MAGIC = 0;
    private const SKIP = 1;
    private const HEADER = 2;
    private const BLOCK_HEADER = 3;
    private const BLOCK = 4;
    private const CHECKSUM = 5;

    /** Input not consumed yet: a partial frame header, block or checksum. */
    private string $buf = '';
    /** How many bytes $buf must hold before the next step can be taken. */
    private int $need = 4;
    private int $stage = self::MAGIC;
    /** Whether any frame (skippable ones included) has begun. */
    private bool $seen = false;
    /** Bytes of a skippable frame still to skip. */
    private int $skip = 0;
    private ?FrameState $st = null;
    /** Output of the finished frames. */
    private int $before = 0;
    /** Bytes of the current frame trimmed from the front of its history. */
    private int $cut = 0;
    /** How much of the history ($st->dst) has been returned already. */
    private int $emitted = 0;
    private ?\HashContext $hash = null;
    private int $blockType = 0;
    private int $blockSize = 0;
    private int $blockLen = 0;
    private bool $blockLast = false;

    /**
     * @param ?int $maxSize the most bytes of output over the whole stream; null for no limit
     * @throws \ValueError if $maxSize is negative
     */
    public function __construct(private readonly ?int $maxSize = null)
    {
        if ($maxSize !== null && $maxSize < 0) {
            throw new \ValueError('$maxSize must not be negative.');
        }
    }

    /**
     * Add input and return the decompressed bytes it completed (possibly '').
     *
     * @throws \UnexpectedValueException
     */
    public function decode(string $chunk): string
    {
        $this->buf .= $chunk;
        if (\strlen($this->buf) < $this->need) {
            return '';
        }
        \set_error_handler(static function (int $no, string $msg): never {
            throw new \UnexpectedValueException('Corrupted Zstandard data (' . $msg . ').');
        });
        try {
            return $this->step();
        } catch (\UnexpectedValueException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \UnexpectedValueException('Corrupted Zstandard data.', 0, $e);
        } finally {
            \restore_error_handler();
        }
    }

    /**
     * End the input: returns any output still held (none: every block's output is returned by decode()), and throws \UnexpectedValueException
     * if the stream is incomplete. Resets the decoder, so that it can decode another stream.
     *
     * @throws \UnexpectedValueException
     */
    public function finish(): string
    {
        $complete = $this->seen && $this->stage === self::MAGIC && $this->buf === '';
        $seen = $this->seen;
        $this->reset();
        if (!$complete) {
            throw new \UnexpectedValueException($seen ? 'Truncated Zstandard data.' : 'Invalid Zstandard frame magic.');
        }
        return '';
    }

    private function reset(): void
    {
        $this->buf = '';
        $this->need = 4;
        $this->stage = self::MAGIC;
        $this->seen = false;
        $this->skip = 0;
        $this->st = null;
        $this->before = 0;
        $this->cut = 0;
        $this->emitted = 0;
        $this->hash = null;
    }

    /** The most output the current frame may still produce (-1: no limit). */
    private function limit(): int
    {
        return $this->maxSize === null ? -1 : $this->maxSize - $this->before;
    }

    /** Takes every step the buffered input allows; returns the output of the blocks it decoded. */
    private function step(): string
    {
        $buf = $this->buf;
        $this->buf = '';
        $len = \strlen($buf);
        $pos = 0;
        $parts = [];
        $need = 0;

        while (true) {
            $avail = $len - $pos;
            if ($this->stage === self::BLOCK) {
                if ($avail < $this->blockLen) {
                    $need = $this->blockLen;
                    break;
                }
                $st = $this->st;
                Engine::decodeBlock($buf, $pos, $this->blockType, $this->blockSize, $st);
                $pos += $this->blockLen;
                $total = $this->cut + \strlen($st->dst);
                $piece = $this->emitted === 0 ? $st->dst : \substr($st->dst, $this->emitted);
                if ($this->hash !== null) {
                    \hash_update($this->hash, $piece);
                }
                $parts[] = $piece;
                $this->emitted = \strlen($st->dst);
                if (!$this->blockLast) {
                    // Trim the history to the window in large steps (the copy is paid for by at least half a window or 1 MiB of output)
                    $held = $this->emitted;
                    if ($held > $st->window + \max($st->window >> 1, 1 << 20)) {
                        $drop = $held - $st->window;
                        $st->dst = \substr($st->dst, $drop);
                        $this->cut += $drop;
                        $this->emitted = $held - $drop;
                    }
                    $this->stage = self::BLOCK_HEADER;
                    continue;
                }
                $limit = $this->limit();
                if ($limit >= 0 && $total > $limit) {
                    throw new \UnexpectedValueException("Zstandard output exceeds the limit of $limit bytes.");
                }
                if ($st->contentSize >= 0 && $total !== $st->contentSize) {
                    throw new \UnexpectedValueException("Zstandard frame decodes to $total bytes, its header says {$st->contentSize}.");
                }
                $this->before += $total;
                $this->st = null;
                $this->cut = 0;
                $this->emitted = 0;
                $this->stage = $st->checksum ? self::CHECKSUM : self::MAGIC;
                continue;
            }
            if ($this->stage === self::BLOCK_HEADER) {
                if ($avail < 3) {
                    $need = 3;
                    break;
                }
                $bh = \ord($buf[$pos]) | (\ord($buf[$pos + 1]) << 8) | (\ord($buf[$pos + 2]) << 16);
                $pos += 3;
                $limit = $this->limit();
                if ($limit >= 0 && $this->cut + \strlen($this->st->dst) > $limit) {
                    throw new \UnexpectedValueException("Zstandard output exceeds the limit of $limit bytes.");
                }
                $this->blockLast = ($bh & 1) !== 0;
                $this->blockType = ($bh >> 1) & 3;
                $this->blockSize = $bh >> 3;
                $this->blockLen = Engine::blockLength($this->blockType, $this->blockSize);
                $this->stage = self::BLOCK;
                continue;
            }
            if ($this->stage === self::MAGIC) {
                if ($avail < 4) {
                    $need = 4;
                    break;
                }
                $magic = \ord($buf[$pos]) | (\ord($buf[$pos + 1]) << 8) | (\ord($buf[$pos + 2]) << 16) | (\ord($buf[$pos + 3]) << 24);
                if (($magic & 0xFFFFFFF0) === 0x184D2A50) {
                    // Skippable frame: a 4-byte size, then that many bytes
                    if ($avail < 8) {
                        $need = 8;
                        break;
                    }
                    $this->skip = \ord($buf[$pos + 4]) | (\ord($buf[$pos + 5]) << 8) | (\ord($buf[$pos + 6]) << 16) | (\ord($buf[$pos + 7]) << 24);
                    $pos += 8;
                    $this->seen = true;
                    $this->stage = self::SKIP;
                    continue;
                }
                if ($magic !== 0xFD2FB528) {
                    throw new \UnexpectedValueException($this->seen ? 'Invalid Zstandard frame magic after a frame.' : 'Invalid Zstandard frame magic.');
                }
                $pos += 4;
                $this->seen = true;
                $this->stage = self::HEADER;
                continue;
            }
            if ($this->stage === self::SKIP) {
                $n = \min($this->skip, $avail);
                $pos += $n;
                $this->skip -= $n;
                if ($this->skip > 0) {
                    $need = 1;
                    break;
                }
                $this->stage = self::MAGIC;
                continue;
            }
            if ($this->stage === self::HEADER) {
                if ($avail < 1) {
                    $need = 1;
                    break;
                }
                $n = Engine::headerSize(\ord($buf[$pos]));
                if ($avail < $n) {
                    $need = $n;
                    break;
                }
                $st = Engine::parseHeader($buf, $pos, $this->limit());
                $this->st = $st;
                $this->cut = 0;
                $this->emitted = 0;
                $this->hash = $st->checksum ? \hash_init('xxh64') : null;
                $this->stage = self::BLOCK_HEADER;
                continue;
            }
            // CHECKSUM: the low 32 bits of XXH64 of the content, little-endian
            if ($avail < 4) {
                $need = 4;
                break;
            }
            if (\substr(\strrev(\hash_final($this->hash, true)), 0, 4) !== \substr($buf, $pos, 4)) {
                throw new \UnexpectedValueException('Zstandard content checksum does not match.');
            }
            $this->hash = null;
            $pos += 4;
            $this->stage = self::MAGIC;
        }

        $this->buf = $pos === 0 ? $buf : \substr($buf, $pos);
        $this->need = $need;
        return \count($parts) === 1 ? $parts[0] : \implode('', $parts);
    }
}

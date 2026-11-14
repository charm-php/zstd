<?php

declare(strict_types=1);

namespace Charm\Zstd\Internal;

/**
 * What a frame encoder carries from block to block: the match finder's hash table and the repeat offsets.
 *
 * @internal
 */
final class EncoderState
{
    /** @var array<int, int> 16,384 entries, each a position in the buffer being compressed (or -1): only a candidate until verified */
    public array $hash = [];
    public int $rep1 = 1;
    public int $rep2 = 4;
    public int $rep3 = 8;
}

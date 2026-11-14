<?php

declare(strict_types=1);

namespace Charm\Zstd\Internal;

/**
 * What a frame decoder carries from block to block: the output so far, the repeat offsets, the FSE tables and the Huffman table
 * that a later block may reuse. (A property of an object, not a by-reference parameter: the block decoder takes the strings and
 * arrays out into locals, which the tracing JIT compiles better.)
 *
 * @internal
 */
final class FrameState
{
    /** The output of the frame so far (a streaming decoder trims it to the window). */
    public string $dst = '';
    /** The window size: the farthest back a match may reach (the content size of a single-segment frame). */
    public int $window = 0;
    /** The content size from the header, -1 when it has none. */
    public int $contentSize = -1;
    public bool $checksum = false;

    public int $rep1 = 1;
    public int $rep2 = 4;
    public int $rep3 = 8;

    /** @var array<int, int> */
    public array $decLL = [];
    /** @var array<int, int> */
    public array $decML = [];
    /** @var array<int, int> */
    public array $decOF = [];
    public int $logLL = 6;
    public int $logML = 6;
    public int $logOF = 5;

    /** @var ?array<int, string> */
    public ?array $hufSym = null;
    /** @var array<int, int> */
    public array $hufNb = [];
    public int $hufLog = 0;
    /** @var ?array<int, string> */
    public ?array $hufWide = null;
    /** @var array<int, int> */
    public array $hufWideNb = [];
}

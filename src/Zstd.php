<?php

declare(strict_types=1);

namespace Charm\Zstd;

/**
 * A userspace Zstandard (RFC 8878) codec in plain PHP.
 *
 * decompress() reads any frame the reference compressor writes without a dictionary: raw, RLE and compressed blocks, raw, RLE,
 * Huffman and treeless literals (1 or 4 streams), predefined, RLE, FSE and repeat sequence tables, content checksums (verified),
 * concatenated and skippable frames. compress() writes single-segment frames: a hash-table match finder, Huffman-coded literals and
 * FSE-compressed (or predefined or RLE) sequence tables chosen per block by size; its output is valid for every decoder.
 *
 * Written for the Zend Engine: packed integer arrays as tables and integer-only loops for the entropy decoding (what the tracing
 * JIT compiles well), substr() and appends for the copies (measured 35-65 % faster than writing the output byte by byte).
 */
final class Zstd
{
    // RFC 8878 Magic Number: 0xFD2FB528
    private const MAGIC = "\x28\xB5\x2F\xFD";

    // Predefined FSE Default Distributions (RFC 8878 Section 3.1.1.3.2.2)
    // Accuracy Log = 6 (64 states)
    private const LL_DEFAULT_NORM = [
        4, 3, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 1, 1, 1,
        2, 2, 2, 2, 2, 2, 2, 2, 2, 3, 2, 1, 1, 1, 1, 1,
        -1, -1, -1, -1
    ];

    // Accuracy Log = 6 (64 states)
    private const ML_DEFAULT_NORM = [
        1, 4, 3, 2, 2, 2, 2, 2, 2, 1, 1, 1, 1, 1, 1, 1,
        1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1,
        1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, -1, -1,
        -1, -1, -1, -1, -1
    ];

    // Accuracy Log = 5 (32 states)
    private const OF_DEFAULT_NORM = [
        1, 1, 1, 1, 1, 1, 2, 2, 2, 1, 1, 1, 1, 1, 1, 1,
        1, 1, 1, 1, 1, 1, 1, 1, -1, -1, -1, -1, -1
    ];

    // Literal Length Base Values & Extra Bits (36 symbols)
    private const LL_BASE = [
        0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15,
        16, 18, 20, 22, 24, 28, 32, 40, 48, 64, 128, 256, 512, 1024, 2048, 4096,
        8192, 16384, 32768, 65536
    ];
    private const LL_BITS = [
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        1, 1, 1, 1, 2, 2, 3, 3, 4, 6, 7, 8, 9, 10, 11, 12,
        13, 14, 15, 16
    ];

    // Match Length Base Values & Extra Bits (53 symbols, matchLen = base + 3)
    private const ML_BASE = [
        3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18,
        19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34,
        35, 37, 39, 41, 43, 47, 51, 59, 67, 83, 99, 131, 259, 515, 1027, 2051,
        4099, 8195, 16387, 32771, 65539
    ];
    private const ML_BITS = [
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        1, 1, 1, 1, 2, 2, 3, 3, 4, 4, 5, 7, 8, 9, 10, 11,
        12, 13, 14, 15, 16
    ];

    private static bool $initialized = false;

    /** the index width of the multi-symbol Huffman table (a code is at most 11 bits) */
    private const HUF_WIDE = 11;

    // Pre-computed flat FSE Decoding Tables (packed arrays, value = (symbol << 24) | (nbBits << 16) | nextStateBase)
    /** @var array<int, int> */
    private static array $decLL = [];
    /** @var array<int, int> */
    private static array $decML = [];
    /** @var array<int, int> */
    private static array $decOF = [];

    // FSE encoding tables of the predefined distributions (FSE_buildCTable): the next state by (state >> nbBits) + deltaFind,
    // states numbered tableSize .. 2 * tableSize - 1; nbBits = (state + deltaNb) >> 16
    /** @var array<int, int> */
    private static array $encLLState = [];
    /** @var array<int, int> */
    private static array $encLLDeltaNb = [];
    /** @var array<int, int> */
    private static array $encLLDeltaFind = [];
    /** @var array<int, int> */
    private static array $encMLState = [];
    /** @var array<int, int> */
    private static array $encMLDeltaNb = [];
    /** @var array<int, int> */
    private static array $encMLDeltaFind = [];
    /** @var array<int, int> */
    private static array $encOFState = [];
    /** @var array<int, int> */
    private static array $encOFDeltaNb = [];
    /** @var array<int, int> */
    private static array $encOFDeltaFind = [];

    // Fast lookup tables for mapping lengths < 128 to LL/ML codes in O(1)
    /** @var array<int, int> */
    private static array $llCodeTable = [];
    /** @var array<int, int> */
    private static array $mlCodeTable = [];

    /**
     * One-time initialization of all static FSE tables and lookup buffers.
     */
    private static function init(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        self::$decLL = self::buildFseDecodeTable(self::LL_DEFAULT_NORM, 6);
        self::$decML = self::buildFseDecodeTable(self::ML_DEFAULT_NORM, 6);
        self::$decOF = self::buildFseDecodeTable(self::OF_DEFAULT_NORM, 5);

        [self::$encLLState, self::$encLLDeltaNb, self::$encLLDeltaFind] = self::buildFseEncodeTable(self::LL_DEFAULT_NORM, 6);
        [self::$encMLState, self::$encMLDeltaNb, self::$encMLDeltaFind] = self::buildFseEncodeTable(self::ML_DEFAULT_NORM, 6);
        [self::$encOFState, self::$encOFDeltaNb, self::$encOFDeltaFind] = self::buildFseEncodeTable(self::OF_DEFAULT_NORM, 5);

        // Precompute fast code lookups
        for ($i = 0; $i < 64; ++$i) {
            self::$llCodeTable[$i] = 0;
        }
        for ($s = 0; $s < 26; ++$s) {
            $base = self::LL_BASE[$s];
            $span = 1 << self::LL_BITS[$s];
            for ($k = 0; $k < $span && ($base + $k) < 64; ++$k) {
                self::$llCodeTable[$base + $k] = $s;
            }
        }

        for ($i = 0; $i < 131; ++$i) {
            self::$mlCodeTable[$i] = 0;
        }
        for ($s = 0; $s < 43; ++$s) {
            $base = self::ML_BASE[$s];
            $span = 1 << self::ML_BITS[$s];
            for ($k = 0; $k < $span && ($base + $k) < 131; ++$k) {
                self::$mlCodeTable[$base + $k] = $s;
            }
        }
    }

    /**
     * Compresses a binary string into a valid RFC 8878 Zstandard (.zst) frame.
     * Finds matches with a hash table of 16 384 entries keyed by 4 bytes (kept between calls: entries are only candidates and
     * are verified before use), checks the most recent offset (repeat offset 1) before the table, writes the literals as raw, RLE or
     * Huffman-coded (one or four streams) and each kind of sequence code with a predefined, RLE or FSE-compressed table, whichever
     * is smaller. Falls back to raw or RLE blocks when that is smaller; a block without a match is its literals only.
     */
    public static function compress(string $data): string
    {
        $src = $data;
        self::init();

        $srcLen = \strlen($src);

        // Frame Header:
        // Magic (4B) + Frame_Header_Descriptor (1B) + Frame_Content_Size (4B or 1B)
        // Descriptor: Single_Segment_Flag = 1, Content_Checksum_Flag = 0
        // If srcLen < 256: FHD = 0x20 (FCS_Field_Size = 1 byte)
        // If srcLen <= 65535 + 256: FHD = 0x60 (FCS_Field_Size = 2 bytes)
        // Else: FHD = 0xA0 (FCS_Field_Size = 4 bytes)
        if ($srcLen < 256) {
            $header = self::MAGIC . "\x20" . \chr($srcLen);
        } elseif ($srcLen <= 65791) {
            $fcs = $srcLen - 256;
            $header = self::MAGIC . "\x60" . \chr($fcs & 0xFF) . \chr(($fcs >> 8) & 0xFF);
        } elseif ($srcLen <= 0xFFFFFFFF) {
            $header = self::MAGIC . "\xA0" . \pack('V', $srcLen);
        } else {
            $header = self::MAGIC . "\xE0" . \pack('P', $srcLen);
        }

        if ($srcLen === 0) {
            // Empty Last Raw Block (header = 0x000001)
            return $header . "\x01\x00\x00";
        }

        // Static workspaces. The hash table (16K entries for 4-byte matches) is never cleared: an entry left by an earlier block or
        // an earlier call is only a candidate, accepted if it lies before the current position and the four bytes there are equal.
        static $hashTable = [];
        static $seqLL = [];
        static $seqML = [];
        static $seqOF = [];

        if ($hashTable === []) {
            $hashTable = \array_fill(0, 16384, -1);
        }

        // Fast-path for tiny inputs (< 16 bytes): emit Raw Block directly
        if ($srcLen < 16) {
            $bh = ($srcLen << 3) | 1; // Last_Block = 1, Block_Type = 0 (Raw)
            return $header . \chr($bh & 0xFF) . \chr(($bh >> 8) & 0xFF) . \chr(($bh >> 16) & 0xFF) . $src;
        }

        // Persistent repeat offsets across blocks in the same frame (RFC 8878: 1, 4, 8)
        $rep1 = 1;
        $rep2 = 4;
        $rep3 = 8;

        $outChunks = [$header];
        $blockOffset = 0;

        while ($blockOffset < $srcLen) {
            $blockSize = $srcLen - $blockOffset;
            if ($blockSize > 131072) {
                $blockSize = 131072; // 128 KB max Zstd block size
            }
            $isLastBlock = ($blockOffset + $blockSize === $srcLen) ? 1 : 0;
            $blockEnd = $blockOffset + $blockSize;
            $matchLimit = $blockEnd - 4;

            $ip = $blockOffset;
            $anchor = $blockOffset;
            $litBuf = '';
            $litLenTotal = 0;
            $seqCount = 0;

            // Save repeat offsets in case we fall back to Raw Block
            $savedRep1 = $rep1;
            $savedRep2 = $rep2;
            $savedRep3 = $rep3;

            // -----------------------------------------------------------------
            // Phase 1: Fast In-Place LZ77 Match Finder with Repeat-Offset Check
            // -----------------------------------------------------------------
            // $v is the four bytes at $ip (little-endian); stepping one byte forward shifts one byte in
            $v = 0;
            if ($ip <= $matchLimit) {
                $v = \ord($src[$ip]) | (\ord($src[$ip + 1]) << 8) | (\ord($src[$ip + 2]) << 16) | (\ord($src[$ip + 3]) << 24);
            }

            while ($ip <= $matchLimit) {
                // Check Repeat Offset 1 first: with literals before it (litLen > 0) it is offset value 1
                $repCandidate = $ip - $rep1;
                $matchIndex = -1;
                $offValue = 0;

                if ($ip > $anchor && $repCandidate >= 0) {
                    $rv = \ord($src[$repCandidate])
                        | (\ord($src[$repCandidate + 1]) << 8)
                        | (\ord($src[$repCandidate + 2]) << 16)
                        | (\ord($src[$repCandidate + 3]) << 24);
                    if ($v === $rv) {
                        $matchIndex = $repCandidate;
                        $offValue = 1;
                    }
                }

                $h = (($v * 0x1E35A7BD) & 0xFFFFFFFF) >> 18;
                $candidate = $hashTable[$h];
                $hashTable[$h] = $ip;

                if ($matchIndex < 0 && $candidate >= 0 && $candidate < $ip && $ip - $candidate <= 131072) {
                    $cv = \ord($src[$candidate])
                        | (\ord($src[$candidate + 1]) << 8)
                        | (\ord($src[$candidate + 2]) << 16)
                        | (\ord($src[$candidate + 3]) << 24);
                    if ($v === $cv) {
                        $matchIndex = $candidate;
                        $rawOffset = $ip - $candidate;
                        $offValue = $rawOffset + 3;
                        $rep3 = $rep2;
                        $rep2 = $rep1;
                        $rep1 = $rawOffset;
                    }
                }

                if ($matchIndex < 0) {
                    // No match: step on, faster the longer nothing is found (incompressible data)
                    if ($ip - $anchor < 32) {
                        if (++$ip > $matchLimit) {
                            break;
                        }
                        $v = ($v >> 8) | (\ord($src[$ip + 3]) << 24);
                    } else {
                        $ip += (($ip - $anchor) >> 5) + 1;
                        if ($ip > $matchLimit) {
                            break;
                        }
                        $v = \ord($src[$ip]) | (\ord($src[$ip + 1]) << 8) | (\ord($src[$ip + 2]) << 16) | (\ord($src[$ip + 3]) << 24);
                    }
                    continue;
                }

                // The literals [$anchor .. $ip)
                $ll = $ip - $anchor;
                if ($ll > 0) {
                    $litBuf .= \substr($src, $anchor, $ll);
                    $litLenTotal += $ll;
                }

                // Extend match forward
                $mLen = 4;
                $maxLen = $blockEnd - $ip;
                while ($mLen < $maxLen && $src[$ip + $mLen] === $src[$matchIndex + $mLen]) {
                    ++$mLen;
                }

                // Store sequence
                $seqLL[$seqCount] = $ll;
                $seqML[$seqCount] = $mLen;
                $seqOF[$seqCount] = $offValue;
                ++$seqCount;

                $ip += $mLen;
                $anchor = $ip;

                if ($ip <= $matchLimit) {
                    // Seed the hash table two bytes back, and read the four bytes at the new position
                    $v2 = \ord($src[$ip - 2])
                        | (\ord($src[$ip - 1]) << 8)
                        | (\ord($src[$ip]) << 16)
                        | (\ord($src[$ip + 1]) << 24);
                    $hashTable[(($v2 * 0x1E35A7BD) & 0xFFFFFFFF) >> 18] = $ip - 2;
                    $v = ($v2 >> 16) | (\ord($src[$ip + 2]) << 16) | (\ord($src[$ip + 3]) << 24);
                }
            }

            // Copy trailing literals after the last match
            $tailLen = $blockEnd - $anchor;
            if ($tailLen > 0) {
                $litBuf .= \substr($src, $anchor, $tailLen);
                $litLenTotal += $tailLen;
            }

            // Check for pure RLE block (entire block is 1 repeated byte)
            if ($seqCount === 1 && $litLenTotal === 1 && $seqML[0] === $blockSize - 1 && ($seqOF[0] === 4 || ($seqOF[0] === 1 && $savedRep1 === 1))) {
                $rep1 = $savedRep1;
                $rep2 = $savedRep2;
                $rep3 = $savedRep3;
                $bh = ($blockSize << 3) | (1 << 1) | $isLastBlock; // Block_Type = 1 (RLE_Block)
                $outChunks[] = \chr($bh & 0xFF) . \chr(($bh >> 8) & 0xFF) . \chr(($bh >> 16) & 0xFF) . $src[$blockOffset];
                $blockOffset += $blockSize;
                continue;
            }

            $rawBlock = static fn (): string => \chr(($blockSize << 3 | $isLastBlock) & 0xFF) . \chr((($blockSize << 3 | $isLastBlock) >> 8) & 0xFF)
                . \chr((($blockSize << 3 | $isLastBlock) >> 16) & 0xFF)
                . ($blockSize === $srcLen ? $src : \substr($src, $blockOffset, $blockSize));

            if ($seqCount === 0) {
                // No match at all: the block is its literals (Huffman-coded when that is smaller); no sequences follow the section
                $litSection = self::encodeLiterals($blockSize === $srcLen ? $src : \substr($src, $blockOffset, $blockSize), $blockSize);
                $compBlockSize = \strlen($litSection) + 1;
                if ($compBlockSize >= $blockSize) {
                    $outChunks[] = $rawBlock();
                } else {
                    $bh = ($compBlockSize << 3) | (2 << 1) | $isLastBlock;
                    $outChunks[] = \chr($bh & 0xFF) . \chr(($bh >> 8) & 0xFF) . \chr(($bh >> 16) & 0xFF) . $litSection . "\x00";
                }
                $blockOffset += $blockSize;
                continue;
            }

            // -----------------------------------------------------------------
            // Phase 2: the codes of the sequences, a table for each kind (predefined, RLE or FSE-compressed), the backward bitstream
            // -----------------------------------------------------------------
            $llCodes = self::$llCodeTable;
            $mlCodes = self::$mlCodeTable;
            $llC = [];
            $mlC = [];
            $ofC = [];
            for ($s = 0; $s < $seqCount; ++$s) {
                $llVal = $seqLL[$s];
                $llC[$s] = $llVal < 64 ? $llCodes[$llVal] : self::getLLCodeSlow($llVal);
                $mlVal = $seqML[$s];
                $mlC[$s] = $mlVal < 131 ? $mlCodes[$mlVal] : self::getMLCodeSlow($mlVal);
                // Offset code = floor(log2(offset value)), by binary search
                $tmpOf = $seqOF[$s];
                $ofCode = 0;
                if ($tmpOf >= 65536) { $tmpOf >>= 16; $ofCode += 16; }
                if ($tmpOf >= 256)   { $tmpOf >>= 8;  $ofCode += 8; }
                if ($tmpOf >= 16)    { $tmpOf >>= 4;  $ofCode += 4; }
                if ($tmpOf >= 4)     { $tmpOf >>= 2;  $ofCode += 2; }
                if ($tmpOf >= 2)     { $ofCode += 1; }
                $ofC[$s] = $ofCode;
            }
            [$llMode, $llDesc, $llTab, $llLog] = self::chooseSequenceTable($llC, $seqCount, 9, self::LL_DEFAULT_NORM, 6, [self::$encLLState, self::$encLLDeltaNb, self::$encLLDeltaFind]);
            [$mlMode, $mlDesc, $mlTab, $mlLog] = self::chooseSequenceTable($mlC, $seqCount, 9, self::ML_DEFAULT_NORM, 6, [self::$encMLState, self::$encMLDeltaNb, self::$encMLDeltaFind]);
            [$ofMode, $ofDesc, $ofTab, $ofLog] = self::chooseSequenceTable($ofC, $seqCount, 8, self::OF_DEFAULT_NORM, 5, [self::$encOFState, self::$encOFDeltaNb, self::$encOFDeltaFind]);

            // The decoder reads the bitstream from its end, so the sequences are written last to first, and within a sequence
            // in the reverse of the order the decoder reads (ZSTD_encodeSequences).
            // Bits gather in $bitContainer (at most 63); 32 at a time go into $bitWords, packed once at the end
            $bitContainer = 0;
            $bitCount = 0;
            $bitWords = [];

            [$llState, $llDeltaNb, $llDeltaFind] = $llTab ?? [[], [], []];
            [$mlState, $mlDeltaNb, $mlDeltaFind] = $mlTab ?? [[], [], []];
            [$ofState, $ofDeltaNb, $ofDeltaFind] = $ofTab ?? [[], [], []];
            $llBaseArr = self::LL_BASE; $llBitsArr = self::LL_BITS; $mlBaseArr = self::ML_BASE; $mlBitsArr = self::ML_BITS;

            $stateLL = 0;
            $stateML = 0;
            $stateOF = 0;

            for ($s = $seqCount - 1; $s >= 0; --$s) {
                $llCode = $llC[$s];
                $mlCode = $mlC[$s];
                $ofCode = $ofC[$s];
                $llVal = $seqLL[$s];
                $mlVal = $seqML[$s];
                $ofVal = $seqOF[$s];

                if ($s === $seqCount - 1) {
                    // The last sequence: the states start at its symbols and no state bits are written (FSE_initCState2)
                    if ($mlMode !== 1) {
                        $d = $mlDeltaNb[$mlCode]; $nb = ($d + 32768) >> 16;
                        $stateML = $mlState[((($nb << 16) - $d) >> $nb) + $mlDeltaFind[$mlCode]];
                    }
                    if ($ofMode !== 1) {
                        $d = $ofDeltaNb[$ofCode]; $nb = ($d + 32768) >> 16;
                        $stateOF = $ofState[((($nb << 16) - $d) >> $nb) + $ofDeltaFind[$ofCode]];
                    }
                    if ($llMode !== 1) {
                        $d = $llDeltaNb[$llCode]; $nb = ($d + 32768) >> 16;
                        $stateLL = $llState[((($nb << 16) - $d) >> $nb) + $llDeltaFind[$llCode]];
                    }
                } else {
                    // The decoder updates LL, ML, OF: the encoder writes OF, ML, LL
                    if ($ofMode !== 1) {
                        $nb = ($stateOF + $ofDeltaNb[$ofCode]) >> 16;
                        $bitContainer |= ($stateOF & ((1 << $nb) - 1)) << $bitCount;
                        $bitCount += $nb;
                        $stateOF = $ofState[($stateOF >> $nb) + $ofDeltaFind[$ofCode]];
                    }
                    if ($mlMode !== 1) {
                        $nb = ($stateML + $mlDeltaNb[$mlCode]) >> 16;
                        $bitContainer |= ($stateML & ((1 << $nb) - 1)) << $bitCount;
                        $bitCount += $nb;
                        $stateML = $mlState[($stateML >> $nb) + $mlDeltaFind[$mlCode]];
                    }
                    if ($llMode !== 1) {
                        $nb = ($stateLL + $llDeltaNb[$llCode]) >> 16;
                        $bitContainer |= ($stateLL & ((1 << $nb) - 1)) << $bitCount;
                        $bitCount += $nb;
                        $stateLL = $llState[($stateLL >> $nb) + $llDeltaFind[$llCode]];
                    }
                    if ($bitCount >= 32) {
                        $bitWords[] = $bitContainer & 0xFFFFFFFF;
                        $bitContainer >>= 32;
                        $bitCount -= 32;
                    }
                }

                // The decoder reads OF_extra, ML_extra, LL_extra: the encoder writes LL_extra, ML_extra, OF_extra
                $llExtraBits = $llBitsArr[$llCode];
                if ($llExtraBits > 0) {
                    $bitContainer |= ($llVal - $llBaseArr[$llCode]) << $bitCount;
                    $bitCount += $llExtraBits;
                }
                $mlExtraBits = $mlBitsArr[$mlCode];
                if ($mlExtraBits > 0) {
                    $bitContainer |= ($mlVal - $mlBaseArr[$mlCode]) << $bitCount;
                    $bitCount += $mlExtraBits;
                }
                if ($bitCount >= 32) {
                    $bitWords[] = $bitContainer & 0xFFFFFFFF;
                    $bitContainer >>= 32;
                    $bitCount -= 32;
                }
                if ($ofCode > 0) {
                    $bitContainer |= ($ofVal & ((1 << $ofCode) - 1)) << $bitCount;
                    $bitCount += $ofCode;
                }

                if ($bitCount >= 32) {
                    $bitWords[] = $bitContainer & 0xFFFFFFFF;
                    $bitContainer >>= 32;
                    $bitCount -= 32;
                }
            }

            // The final states (the decoder reads LL, OF, ML first, each as many bits as its accuracy log): ML, OF, LL, then the sentinel bit
            if ($mlMode !== 1) {
                $bitContainer |= ($stateML & ((1 << $mlLog) - 1)) << $bitCount;
                $bitCount += $mlLog;
            }
            if ($ofMode !== 1) {
                $bitContainer |= ($stateOF & ((1 << $ofLog) - 1)) << $bitCount;
                $bitCount += $ofLog;
            }
            if ($llMode !== 1) {
                $bitContainer |= ($stateLL & ((1 << $llLog) - 1)) << $bitCount;
                $bitCount += $llLog;
            }
            $bitContainer |= (1 << $bitCount);
            ++$bitCount;

            $bitBuf = ($bitWords === [] ? '' : \pack('V*', ...$bitWords)) . \substr(\pack('P', $bitContainer), 0, ($bitCount + 7) >> 3);

            $litSection = self::encodeLiterals($litBuf, $litLenTotal);

            // Number_of_Sequences, then Symbol_Compression_Modes and the table descriptions (LL, OF, ML)
            if ($seqCount < 128) {
                $seqHeader = \chr($seqCount);
            } elseif ($seqCount < 32512) {
                $seqHeader = \chr((($seqCount >> 8) & 0xFF) + 128) . \chr($seqCount & 0xFF);
            } else {
                $sc = $seqCount - 0x7F00;
                $seqHeader = "\xFF" . \chr($sc & 0xFF) . \chr(($sc >> 8) & 0xFF);
            }
            $seqHeader .= \chr(($llMode << 6) | ($ofMode << 4) | ($mlMode << 2)) . $llDesc . $ofDesc . $mlDesc;

            $compBlockSize = \strlen($litSection) + \strlen($seqHeader) + \strlen($bitBuf);
            if ($compBlockSize >= $blockSize) {
                // Compressed turned out larger than raw; emit Raw_Block
                $rep1 = $savedRep1;
                $rep2 = $savedRep2;
                $rep3 = $savedRep3;
                $outChunks[] = $rawBlock();
            } else {
                // Block_Type = 2 (Compressed_Block)
                $bh = ($compBlockSize << 3) | (2 << 1) | $isLastBlock;
                $outChunks[] = \chr($bh & 0xFF) . \chr(($bh >> 8) & 0xFF) . \chr(($bh >> 16) & 0xFF)
                    . $litSection
                    . $seqHeader
                    . $bitBuf;
            }

            $blockOffset += $blockSize;
        }

        return \implode('', $outChunks);
    }

    // ------------------------------------------------------------------------------------------------------------------
    // Compressor: entropy coding of the literals (Huffman) and of the sequence codes (FSE tables built per block)
    // ------------------------------------------------------------------------------------------------------------------

    /** The longest Huffman code the format allows. */
    private const HUF_MAX_BITS = 11;

    /** @var int[] bits (a raw/RLE header's first byte, or the start of a compressed literals header) */
    private static function literalsHeaderRaw(int $n, int $type): string
    {
        if ($n < 32) {
            return \chr(($n << 3) | $type);
        }
        if ($n < 4096) {
            $lh = ($n << 4) | 0x04 | $type;
            return \chr($lh & 0xFF) . \chr($lh >> 8);
        }
        $lh = ($n << 4) | 0x0C | $type;
        return \chr($lh & 0xFF) . \chr(($lh >> 8) & 0xFF) . \chr($lh >> 16);
    }

    /**
     * The literals section of a block: the smallest of raw, RLE and Huffman-coded (one stream below 256 literals, four above), by
     * the sizes actually produced. A histogram says first whether Huffman coding can pay at all (it cannot for near-uniform bytes).
     */
    private static function encodeLiterals(string $lit, int $n): string
    {
        $raw = self::literalsHeaderRaw($n, 0);
        if ($n < 32) {
            return $raw . $lit;
        }
        $counts = \count_chars($lit, 1);
        if (\count($counts) === 1) {
            return self::literalsHeaderRaw($n, 1) . $lit[0];
        }
        // Entropy bound: if even an ideal code could save under 3 percent, the tree and the coding time are not worth it
        $bits = 0.0;
        foreach ($counts as $c) {
            $bits += $c * \log($n / $c, 2);
        }
        if ($bits * 0.125 >= $n * 0.97) {
            return $raw . $lit;
        }
        $huf = self::huffmanLiterals($lit, $n, $counts);
        return $huf !== null && \strlen($huf) < $n + \strlen($raw) ? $huf : $raw . $lit;
    }

    /**
     * A Huffman-compressed literals section (type 2) or null when no tree can be written for these counts.
     *
     * @param array<int, int> $counts byte value => count (nonzero only), at least two symbols
     */
    private static function huffmanLiterals(string $lit, int $n, array $counts): ?string
    {
        $lens = self::huffmanLengths($counts, self::HUF_MAX_BITS);
        $maxBits = \max($lens);
        $last = \max(\array_keys($lens));
        $weights = [];
        $bySymbol = [];
        for ($s = 0; $s <= $last; ++$s) {
            $w = isset($lens[$s]) ? $maxBits + 1 - $lens[$s] : 0;
            $weights[$s] = $w;
            if ($w > 0) {
                $bySymbol[$w][] = $s;
            }
        }
        $tree = self::huffmanTree(\array_slice($weights, 0, $last), $last);
        if ($tree === null) {
            return null;
        }
        // Codes: the entries of the decoding table go by weight, then symbol; a symbol of weight w owns the 2^(w-1) entries from
        // $at, and its code is the first $maxBits + 1 - w bits of that index
        $code = \array_fill(0, 256, 0);
        $len = \array_fill(0, 256, 0);
        $at = 0;
        for ($w = 1; $w <= $maxBits; ++$w) {
            foreach ($bySymbol[$w] ?? [] as $s) {
                $code[$s] = $at >> ($w - 1);
                $len[$s] = $maxBits + 1 - $w;
                $at += 1 << ($w - 1);
            }
        }
        $bytes = \unpack('C*', $lit);
        if ($n < 256) {
            $streams = [self::huffmanStream($bytes, 1, $n, $code, $len)];
            $jump = '';
        } else {
            $seg = ($n + 3) >> 2;
            $streams = [
                self::huffmanStream($bytes, 1, $seg, $code, $len),
                self::huffmanStream($bytes, $seg + 1, 2 * $seg, $code, $len),
                self::huffmanStream($bytes, 2 * $seg + 1, 3 * $seg, $code, $len),
                self::huffmanStream($bytes, 3 * $seg + 1, $n, $code, $len),
            ];
            $jump = \pack('v3', \strlen($streams[0]), \strlen($streams[1]), \strlen($streams[2]));
        }
        $body = $tree . $jump . \implode('', $streams);
        $comp = \strlen($body);
        if (\count($streams) === 1) {
            if ($comp > 1023) {
                return null;
            }
            $h = 2 | ($n << 4) | ($comp << 14);
            return \substr(\pack('V', $h), 0, 3) . $body;
        }
        if ($n <= 1023 && $comp <= 1023) {
            $h = 2 | (1 << 2) | ($n << 4) | ($comp << 14);
            return \substr(\pack('V', $h), 0, 3) . $body;
        }
        if ($n <= 16383 && $comp <= 16383) {
            $h = 2 | (2 << 2) | ($n << 4) | ($comp << 18);
            return \pack('V', $h) . $body;
        }
        if ($n <= 262143 && $comp <= 262143) {
            $h = 2 | (3 << 2) | ($n << 4) | ($comp << 22);
            return \substr(\pack('P', $h), 0, 5) . $body;
        }
        return null;
    }

    /**
     * One Huffman bitstream (RFC 8878 4.2.2): the symbols of $bytes[$from .. $to] (1-based, from unpack('C*')) in reverse order, each
     * code appended above the bits before it, then the sentinel bit. The decoder reads it backward and takes the first symbol's code
     * from the top.
     *
     * @param array<int, int> $bytes
     * @param array<int, int> $code
     * @param array<int, int> $len
     */
    private static function huffmanStream(array $bytes, int $from, int $to, array $code, array $len): string
    {
        $words = [];
        $acc = 0;
        $nb = 0;
        for ($i = $to; $i >= $from; --$i) {
            $c = $bytes[$i];
            $acc |= $code[$c] << $nb;
            $nb += $len[$c];
            if ($nb >= 32) {
                $words[] = $acc & 0xFFFFFFFF;
                $acc >>= 32;
                $nb -= 32;
            }
        }
        $acc |= 1 << $nb;
        ++$nb;
        return ($words === [] ? '' : \pack('V*', ...$words)) . \substr(\pack('P', $acc), 0, ($nb + 7) >> 3);
    }

    /**
     * Code lengths of a Huffman code over $counts (byte => count) no longer than $limit bits: the optimal code by the two-queue
     * method, then, when it is too deep, the lengths are clamped and the Kraft sum is repaired (deepest codes lengthened first, then
     * the most frequent shortened while it still fits), which keeps the code complete.
     *
     * @param array<int, int> $counts
     * @return array<int, int> byte => length
     */
    private static function huffmanLengths(array $counts, int $limit): array
    {
        \asort($counts);
        $syms = \array_keys($counts);
        $weight = \array_values($counts);
        $m = \count($syms);
        $parent = [];
        $i = 0;
        $j = $m;
        $nodes = $m;
        for ($k = 0; $k < $m - 1; ++$k) {
            $pick = [0, 0];
            for ($t = 0; $t < 2; ++$t) {
                if ($i < $m && ($j >= $nodes || $weight[$i] <= $weight[$j])) {
                    $pick[$t] = $i++;
                } else {
                    $pick[$t] = $j++;
                }
            }
            $weight[$nodes] = $weight[$pick[0]] + $weight[$pick[1]];
            $parent[$pick[0]] = $nodes;
            $parent[$pick[1]] = $nodes;
            ++$nodes;
        }
        $depth = [$nodes - 1 => 0];
        for ($x = $nodes - 2; $x >= 0; --$x) {
            $depth[$x] = $depth[$parent[$x]] + 1;
        }
        $lens = [];
        $deepest = 0;
        for ($x = 0; $x < $m; ++$x) {
            $lens[$syms[$x]] = $depth[$x];
            $deepest = \max($deepest, $depth[$x]);
        }
        if ($deepest <= $limit) {
            return $lens;
        }
        $kraft = 0;
        foreach ($lens as $s => $l) {
            if ($l > $limit) {
                $lens[$s] = $l = $limit;
            }
            $kraft += 1 << ($limit - $l);
        }
        $target = 1 << $limit;
        // too much code space: lengthen the deepest of the rarest symbols (a lengthened code frees 2^(limit - new length))
        while ($kraft > $target) {
            $best = -1;
            foreach ($lens as $s => $l) {
                if ($l < $limit && ($best < 0 || $l > $lens[$best] || ($l === $lens[$best] && $counts[$s] < $counts[$best]))) {
                    $best = $s;
                }
            }
            ++$lens[$best];
            $kraft -= 1 << ($limit - $lens[$best]);
        }
        // space left over: shorten the most frequent symbols that fit
        if ($kraft < $target) {
            \arsort($counts);
            $changed = true;
            while ($kraft < $target && $changed) {
                $changed = false;
                foreach ($counts as $s => $_) {
                    if ($lens[$s] > 1 && $kraft + (1 << ($limit - $lens[$s])) <= $target) {
                        $kraft += 1 << ($limit - $lens[$s]);
                        --$lens[$s];
                        $changed = true;
                        if ($kraft === $target) {
                            break;
                        }
                    }
                }
            }
        }
        return $lens;
    }

    /**
     * The Huffman tree description (RFC 8878 4.2.1): the weights of the symbols 0 .. $last - 1 (the weight of the last symbol is
     * implied), direct as 4-bit weights when $last is at most 128, or as an FSE-compressed stream, whichever is smaller. Null when
     * neither can represent them.
     *
     * @param list<int> $weights
     */
    private static function huffmanTree(array $weights, int $last): ?string
    {
        $direct = null;
        if ($last <= 128) {
            $direct = \chr(127 + $last);
            for ($i = 0; $i < $last; $i += 2) {
                $direct .= \chr(($weights[$i] << 4) | ($i + 1 < $last ? $weights[$i + 1] : 0));
            }
        }
        $fse = null;
        if ($last >= 2 && \count(\array_unique($weights)) >= 2) {
            $body = self::fseCompress($weights, 6);
            if ($body !== null && \strlen($body) < 128) {
                $fse = \chr(\strlen($body)) . $body;
            }
        }
        if ($fse !== null && ($direct === null || \strlen($fse) < \strlen($direct))) {
            return $fse;
        }
        return $direct;
    }

    /**
     * FSE-compresses a symbol list into a table description and the bitstream of two interleaved states (the Huffman weights
     * stream, RFC 8878 4.2.1.2): the even positions use the first state, the odd the second.
     *
     * @param list<int> $syms at least two symbols with at least two distinct values
     */
    private static function fseCompress(array $syms, int $maxLog): ?string
    {
        $n = \count($syms);
        $hist = \array_count_values($syms);
        $maxSym = \max(\array_keys($hist));
        $log = self::fseTableLog($n, $maxSym, $maxLog);
        $norm = self::fseNormalize($hist, $n, $maxSym, $log);
        $desc = self::fseWriteNCount($norm, $log);
        [$state, $dNb, $dFind] = self::buildFseEncodeTable($norm, $log);
        $mask = (1 << $log) - 1;
        // initial states from the last two symbols (no bits written for them)
        $st = [0, 0];
        foreach ([$n - 1, $n - 2] as $idx) {
            $sym = $syms[$idx];
            $d = $dNb[$sym];
            $nb = ($d + 32768) >> 16;
            $st[$idx & 1] = $state[((($nb << 16) - $d) >> $nb) + $dFind[$sym]];
        }
        $acc = 0;
        $bits = 0;
        $words = [];
        for ($i = $n - 3; $i >= 0; --$i) {
            $sym = $syms[$i];
            $p = $i & 1;
            $nb = ($st[$p] + $dNb[$sym]) >> 16;
            $acc |= ($st[$p] & ((1 << $nb) - 1)) << $bits;
            $bits += $nb;
            $st[$p] = $state[($st[$p] >> $nb) + $dFind[$sym]];
            if ($bits >= 32) {
                $words[] = $acc & 0xFFFFFFFF;
                $acc >>= 32;
                $bits -= 32;
            }
        }
        // the decoder reads state 1 first, then state 2 (from the top): write 2 then 1
        $acc |= ($st[1] & $mask) << $bits;
        $bits += $log;
        $acc |= ($st[0] & $mask) << $bits;
        $bits += $log;
        $acc |= 1 << $bits;
        ++$bits;
        return $desc . ($words === [] ? '' : \pack('V*', ...$words)) . \substr(\pack('P', $acc), 0, ($bits + 7) >> 3);
    }

    /** The accuracy log for $n symbols of an alphabet up to $maxSym: small inputs do not need a large table (FSE_optimalTableLog). */
    private static function fseTableLog(int $n, int $maxSym, int $maxLog): int
    {
        $maxBitsSrc = $n > 1 ? self::highBit($n - 1) - 1 : 5;
        $minBits = self::highBit($maxSym) + 2;
        $log = \min($maxLog, $maxBitsSrc);
        $log = \max($log, $minBits, 5);
        return \min($log, \max($maxLog, $minBits));
    }

    /**
     * Normalised counts summing to 2^$log: every present symbol at least 1, the rest in proportion; the rounding error goes to the
     * symbols with the largest fractional part (too little) or the largest count (too much).
     *
     * @param array<int, int> $hist symbol => count
     * @return list<int>
     */
    private static function fseNormalize(array $hist, int $total, int $maxSym, int $log): array
    {
        $size = 1 << $log;
        $norm = \array_fill(0, $maxSym + 1, 0);
        $frac = [];
        $sum = 0;
        foreach ($hist as $s => $c) {
            $q = \intdiv($c * $size, $total);
            $r = ($c * $size) % $total;
            if ($q === 0) {
                $q = 1;
                $r = -$total;
            }
            $norm[$s] = $q;
            $frac[$s] = $r;
            $sum += $q;
        }
        while ($sum < $size) {
            $best = -1;
            foreach ($frac as $s => $r) {
                if ($best < 0 || $r > $frac[$best]) {
                    $best = $s;
                }
            }
            ++$norm[$best];
            $frac[$best] -= $total;
            ++$sum;
        }
        while ($sum > $size) {
            $best = -1;
            foreach ($hist as $s => $_) {
                if ($norm[$s] > 1 && ($best < 0 || $norm[$s] > $norm[$best])) {
                    $best = $s;
                }
            }
            --$norm[$best];
            --$sum;
        }
        return $norm;
    }

    /**
     * The table description of normalised counts (RFC 8878 4.1.1, FSE_writeNCount): 4 bits of accuracy log - 5, then every count
     * in as few bits as the remaining total allows, zero counts followed by 2-bit repeat counts.
     *
     * @param list<int> $norm counts of the symbols 0 .. maxSymbol (the last is not zero)
     */
    private static function fseWriteNCount(array $norm, int $log): string
    {
        $out = '';
        $acc = $log - 5;
        $bits = 4;
        $alphabet = \count($norm);
        $remaining = (1 << $log) + 1;
        $threshold = 1 << $log;
        $nbBits = $log + 1;
        $sym = 0;
        $prevZero = false;
        $put = static function (int $v, int $nb) use (&$acc, &$bits, &$out): void {
            $acc |= $v << $bits;
            $bits += $nb;
            while ($bits >= 8) {
                $out .= \chr($acc & 0xFF);
                $acc >>= 8;
                $bits -= 8;
            }
        };
        while ($sym < $alphabet && $remaining > 1) {
            if ($prevZero) {
                $start = $sym;
                while ($sym < $alphabet && $norm[$sym] === 0) {
                    ++$sym;
                }
                if ($sym === $alphabet) {
                    break;
                }
                while ($sym >= $start + 24) {
                    $start += 24;
                    $put(0xFFFF, 16);
                }
                while ($sym >= $start + 3) {
                    $start += 3;
                    $put(3, 2);
                }
                $put($sym - $start, 2);
            }
            $count = $norm[$sym++];
            $max = (2 * $threshold - 1) - $remaining;
            $remaining -= $count < 0 ? -$count : $count;
            ++$count;
            if ($count >= $threshold) {
                $count += $max;
            }
            $put($count, $nbBits - ($count < $max ? 1 : 0));
            $prevZero = $count === 1;
            while ($remaining < $threshold) {
                --$nbBits;
                $threshold >>= 1;
            }
        }
        if ($bits > 0) {
            $out .= \chr($acc & 0xFF);
        }
        return $out;
    }

    /**
     * The table for one kind of sequence code in one block: predefined (mode 0), RLE (1) or FSE-compressed (2), whichever the
     * estimated size says is smallest (the estimate is the ideal code length of the codes plus the size of the description).
     *
     * @param list<int> $codes
     * @param list<int> $defaultNorm
     * @return array{0: int, 1: string, 2: ?array, 3: int} mode, description, [state, deltaNb, deltaFind] or null (RLE), accuracy log
     */
    private static function chooseSequenceTable(array $codes, int $nSeq, int $maxLog, array $defaultNorm, int $defaultLog, array $defaultTables): array
    {
        $hist = \array_count_values($codes);
        $maxSym = \max(\array_keys($hist));
        $defaultSize = 1 << $defaultLog;
        $predef = 0.0;
        foreach ($hist as $s => $c) {
            $p = $defaultNorm[$s] ?? 0;
            if ($p === 0) {
                $predef = INF;
                break;
            }
            $predef += $c * \log($defaultSize / \abs($p), 2);
        }
        if (\count($hist) === 1) {
            return $predef <= 8 && $predef !== INF ? [0, '', $defaultTables, $defaultLog] : [1, \chr($maxSym), null, 0];
        }
        $log = self::fseTableLog($nSeq, $maxSym, $maxLog);
        $norm = self::fseNormalize($hist, $nSeq, $maxSym, $log);
        $desc = self::fseWriteNCount($norm, $log);
        $size = 1 << $log;
        $fse = 8.0 * \strlen($desc);
        foreach ($hist as $s => $c) {
            $fse += $c * \log($size / $norm[$s], 2);
        }
        if ($predef <= $fse) {
            return [0, '', $defaultTables, $defaultLog];
        }
        return [2, $desc, self::buildFseEncodeTable($norm, $log), $log];
    }

    /**
     * Decompresses Zstandard data (one frame, or several concatenated; skippable frames are skipped) into the original bytes.
     *
     * @param int|null $maxSize stop with a DecodeException as soon as the output would exceed this many bytes. The check is made
     *                          once per block (at most 128 KiB of output), so at most one block more than $maxSize is produced
     *                          before it throws; a frame header that declares more than the limit is rejected before decoding.
     *
     * @throws DecodeException for every failure caused by the input: not a Zstandard frame, corrupt or truncated data, a checksum
     *                         mismatch, a frame that needs a dictionary, or output above $maxSize
     */
    public static function decompress(string $data, ?int $maxSize = null): string
    {
        $src = $data;
        self::init();

        $srcLen = \strlen($src);
        if ($srcLen < 5 || \substr($src, 0, 4) !== self::MAGIC && (\ord($src[0]) & 0xF0) !== 0x50) {
            throw new DecodeException('Invalid Zstandard frame magic.');
        }
        if ($maxSize !== null && $maxSize < 0) {
            throw new \InvalidArgumentException('$maxSize must not be negative.');
        }

        // Reads past the end of corrupt input must not escape as warnings: they become DecodeExceptions below.
        \set_error_handler(static function (int $no, string $msg): never {
            throw new DecodeException('Corrupted Zstandard data (' . $msg . ').');
        });
        try {
            $pos = 0;
            $out = null;
            $total = 0;
            while ($pos < $srcLen) {
                if ($srcLen - $pos < 4) {
                    throw new DecodeException('Truncated Zstandard data.');
                }
                $magic = \ord($src[$pos]) | (\ord($src[$pos + 1]) << 8) | (\ord($src[$pos + 2]) << 16) | (\ord($src[$pos + 3]) << 24);
                if (($magic & 0xFFFFFFF0) === 0x184D2A50) {
                    // Skippable frame: a 4-byte size, then that many bytes
                    if ($srcLen - $pos < 8) {
                        throw new DecodeException('Truncated Zstandard skippable frame.');
                    }
                    $pos += 8 + (\ord($src[$pos + 4]) | (\ord($src[$pos + 5]) << 8) | (\ord($src[$pos + 6]) << 16) | (\ord($src[$pos + 7]) << 24));
                    if ($pos > $srcLen) {
                        throw new DecodeException('Truncated Zstandard skippable frame.');
                    }
                    continue;
                }
                if ($magic !== 0xFD2FB528) {
                    throw new DecodeException($out === null ? 'Invalid Zstandard frame magic.' : 'Invalid Zstandard frame magic after a frame.');
                }
                $frame = self::decodeFrame($src, $pos, $maxSize === null ? -1 : $maxSize - $total);
                $total += \strlen($frame);
                $out = $out === null ? $frame : $out . $frame;
            }
        } catch (DecodeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // (a read past the start of a bitstream is a shift by a negative number: ArithmeticError; anything else the
            // checks above missed is also corrupt input)
            throw new DecodeException('Corrupted Zstandard data.', 0, $e);
        } finally {
            \restore_error_handler();
        }

        return $out ?? '';
    }

    /**
     * Decodes the frame that starts at $pos (its magic number) and moves $pos past it. $limit is the most output bytes it may
     * produce (-1: no limit).
     */
    private static function decodeFrame(string $src, int &$pos, int $limit): string
    {
        $srcLen = \strlen($src);
        $pos += 4;
        if ($pos >= $srcLen) {
            throw new DecodeException('Truncated Zstandard frame header.');
        }
        $fhd = \ord($src[$pos++]);

        $fcsFlag = ($fhd >> 6) & 3;
        $singleSegment = ($fhd >> 5) & 1;
        $checksumFlag = ($fhd >> 2) & 1;
        $dictIdFlag = $fhd & 3;
        if (($fhd & 0x08) !== 0) {
            throw new DecodeException('Reserved bit set in the Zstandard frame header.');
        }

        // The rest of the header: window descriptor, dictionary id, content size (their sizes are fixed by the flags)
        if ($pos + (1 - $singleSegment) + (0x4210 >> ($dictIdFlag << 2) & 15) + ($fcsFlag === 0 ? $singleSegment : (1 << $fcsFlag)) > $srcLen) {
            throw new DecodeException('Truncated Zstandard frame header.');
        }

        // Window Descriptor (present if Single_Segment_Flag == 0)
        if ($singleSegment === 0) {
            ++$pos;
        }

        // Dictionary_ID
        if ($dictIdFlag !== 0) {
            $n = 1 << ($dictIdFlag - 1);
            $dictId = 0;
            for ($i = 0; $i < $n; ++$i) {
                $dictId |= \ord($src[$pos + $i]) << ($i << 3);
            }
            $pos += $n;
            if ($dictId !== 0) {
                throw new DecodeException('Zstandard frame needs a dictionary (' . $dictId . '): not supported.');
            }
        }

        // Frame_Content_Size (-1: not given)
        $contentSize = -1;
        if ($fcsFlag === 0) {
            if ($singleSegment !== 0) {
                $contentSize = \ord($src[$pos++]);
            }
        } elseif ($fcsFlag === 1) {
            $contentSize = (\ord($src[$pos]) | (\ord($src[$pos + 1]) << 8)) + 256;
            $pos += 2;
        } elseif ($fcsFlag === 2) {
            $contentSize = \ord($src[$pos])
                | (\ord($src[$pos + 1]) << 8)
                | (\ord($src[$pos + 2]) << 16)
                | (\ord($src[$pos + 3]) << 24);
            $pos += 4;
        } else {
            $lo = \ord($src[$pos])
                | (\ord($src[$pos + 1]) << 8)
                | (\ord($src[$pos + 2]) << 16)
                | (\ord($src[$pos + 3]) << 24);
            $hi = \ord($src[$pos + 4])
                | (\ord($src[$pos + 5]) << 8)
                | (\ord($src[$pos + 6]) << 16)
                | (\ord($src[$pos + 7]) << 24);
            $contentSize = ($hi << 32) | $lo;
            $pos += 8;
            if ($contentSize < 0) {
                throw new DecodeException('Zstandard frame declares a content size above PHP_INT_MAX.');
            }
        }
        if ($limit >= 0 && $contentSize > $limit) {
            throw new DecodeException("Zstandard frame declares $contentSize bytes, above the limit of $limit.");
        }

        // The output grows by appending: literals and matches are copied by substr(), in C ($op is its length)
        $dst = '';
        $op = 0;

        // Repeat offsets state (RFC 8878 Section 3.1.1.5)
        $rep1 = 1;
        $rep2 = 4;
        $rep3 = 8;

        // Current active FSE decoding tables (support Repeat_Mode across blocks)
        $curDecLL = self::$decLL;
        $curDecML = self::$decML;
        $curDecOF = self::$decOF;
        $curLogLL = 6;
        $curLogML = 6;
        $curLogOF = 5;

        // The Huffman table of the literals (Treeless_Literals_Block reuses the one before)
        $hufSym = null;
        $hufNb = [];
        $hufLog = 0;
        // The same table by the next HUF_WIDE bits, each entry every symbol whose code lies wholly in them (built when a block
        // has enough literals to pay for it)
        $hufWide = null;
        $hufWideNb = [];

        $llBaseArr = self::LL_BASE;
        $llBitsArr = self::LL_BITS;
        $mlBaseArr = self::ML_BASE;
        $mlBitsArr = self::ML_BITS;

        do {
            if ($pos + 3 > $srcLen) {
                throw new DecodeException('Truncated Zstandard frame (block header).');
            }
            $bh = \ord($src[$pos]) | (\ord($src[$pos + 1]) << 8) | (\ord($src[$pos + 2]) << 16);
            $pos += 3;

            $isLastBlock = $bh & 1;
            $blockType = ($bh >> 1) & 3;
            $blockSize = $bh >> 3;

            // The size limit, checked once per block (the output so far is at most one block over it)
            if ($limit >= 0 && $op > $limit) {
                throw new DecodeException("Zstandard output exceeds the limit of $limit bytes.");
            }
            if ($blockSize > 131072) {
                throw new DecodeException('Zstandard block larger than 128 KiB.');
            }

            if ($blockType === 0) {
                // Raw_Block
                if ($pos + $blockSize > $srcLen) {
                    throw new DecodeException('Truncated Zstandard frame (raw block).');
                }
                $dst .= \substr($src, $pos, $blockSize);
                $op += $blockSize;
                $pos += $blockSize;
                continue;
            }

            if ($blockType === 1) {
                // RLE_Block
                if ($pos >= $srcLen) {
                    throw new DecodeException('Truncated Zstandard frame (RLE block).');
                }
                $dst .= \str_repeat($src[$pos++], $blockSize);
                $op += $blockSize;
                continue;
            }

            if ($blockType !== 2) {
                throw new DecodeException('Reserved Zstd block type encountered.');
            }

            // -----------------------------------------------------------------
            // Compressed_Block Decoding
            // -----------------------------------------------------------------
            $blockEnd = $pos + $blockSize;
            if ($blockEnd > $srcLen || $blockSize < 2) {
                throw new DecodeException('Truncated Zstandard frame (compressed block).');
            }
            $bp = $pos;

            // 1. Literals Section: afterwards the literals are $lit[$litPtr .. $litEnd)
            $litByte0 = \ord($src[$bp]);
            $litBlockType = $litByte0 & 3;
            $sizeFormat = ($litByte0 >> 2) & 3;

            if ($litBlockType < 2) {
                // Raw_Literals_Block (0) or RLE_Literals_Block (1)
                if ($sizeFormat === 0 || $sizeFormat === 2) {
                    $litSize = $litByte0 >> 3;
                    ++$bp;
                } elseif ($sizeFormat === 1) {
                    $litSize = ($litByte0 | (\ord($src[$bp + 1]) << 8)) >> 4;
                    $bp += 2;
                } else {
                    $litSize = ($litByte0 | (\ord($src[$bp + 1]) << 8) | (\ord($src[$bp + 2]) << 16)) >> 4;
                    $bp += 3;
                }

                if ($litBlockType === 0) {
                    // (the literals are read where they are: no copy)
                    $lit = $src;
                    $litPtr = $bp;
                    $bp += $litSize;
                } else {
                    $lit = \str_repeat($src[$bp++], $litSize);
                    $litPtr = 0;
                }
            } else {
                // Compressed_Literals_Block (2) or Treeless_Literals_Block (3): Huffman
                if ($sizeFormat < 2) {
                    $h = $litByte0 | (\ord($src[$bp + 1]) << 8) | (\ord($src[$bp + 2]) << 16);
                    $litSize = ($h >> 4) & 0x3FF;
                    $compSize = $h >> 14;
                    $bp += 3;
                } elseif ($sizeFormat === 2) {
                    $h = $litByte0 | (\ord($src[$bp + 1]) << 8) | (\ord($src[$bp + 2]) << 16) | (\ord($src[$bp + 3]) << 24);
                    $litSize = ($h >> 4) & 0x3FFF;
                    $compSize = $h >> 18;
                    $bp += 4;
                } else {
                    $h = $litByte0 | (\ord($src[$bp + 1]) << 8) | (\ord($src[$bp + 2]) << 16) | (\ord($src[$bp + 3]) << 24) | (\ord($src[$bp + 4]) << 32);
                    $litSize = ($h >> 4) & 0x3FFFF;
                    $compSize = $h >> 22;
                    $bp += 5;
                }
                $litStreamsEnd = $bp + $compSize;
                if ($litStreamsEnd > $blockEnd) {
                    throw new DecodeException('Corrupted Zstandard literals section.');
                }

                if ($litBlockType === 2) {
                    [$hufSym, $hufNb, $hufLog, $bp] = self::readHuffmanTable($src, $bp);
                    $hufWide = null;
                } elseif ($hufSym === null) {
                    throw new DecodeException('Zstandard treeless literals without a Huffman table before them.');
                }
                // Single symbols are written in place into a string of the final size (appending one byte at a time is slower).
                // The multi-symbol table appends strings: worth its 2,048 entries when the block has many literals in short
                // codes (under 5 bits each: two or more a lookup).
                $wide = $litSize >= 32768 && ($compSize << 3) < $litSize * 5;
                if ($wide && $hufWide === null) {
                    [$hufWide, $hufWideNb] = self::buildHuffmanWide($hufSym, $hufNb, $hufLog);
                }
                $streams = [];
                if ($sizeFormat === 0) {
                    $streams[] = [$bp, $litStreamsEnd, $litSize];
                } else {
                    // 4 streams: a jump table of three sizes, the streams one after another
                    $s1 = \ord($src[$bp]) | (\ord($src[$bp + 1]) << 8);
                    $s2 = \ord($src[$bp + 2]) | (\ord($src[$bp + 3]) << 8);
                    $s3 = \ord($src[$bp + 4]) | (\ord($src[$bp + 5]) << 8);
                    $bp += 6;
                    $part = ($litSize + 3) >> 2;
                    if ($bp + $s1 + $s2 + $s3 >= $litStreamsEnd || 3 * $part > $litSize) {
                        throw new DecodeException('Corrupted Zstandard literals jump table.');
                    }
                    $streams = [[$bp, $bp + $s1, $part], [$bp + $s1, $bp + $s1 + $s2, $part], [$bp + $s1 + $s2, $bp + $s1 + $s2 + $s3, $part],
                        [$bp + $s1 + $s2 + $s3, $litStreamsEnd, $litSize - 3 * $part]];
                }
                if ($wide) {
                    $lit = '';
                    $done = 0;
                    foreach ($streams as [$from, $to, $count]) {
                        $done += $count;
                        self::decodeHuffmanStreamWide($src, $from, $to, $done, $hufWide, $hufWideNb, self::HUF_WIDE, $hufSym, $hufNb, $hufLog, $lit);
                    }
                } else {
                    $lit = \str_repeat("\x00", $litSize);
                    $done = 0;
                    foreach ($streams as [$from, $to, $count]) {
                        self::decodeHuffmanStream($src, $from, $to, $count, $hufSym, $hufNb, $hufLog, $lit, $done);
                        $done += $count;
                    }
                }
                $bp = $litStreamsEnd;
                $litPtr = 0;
            }
            $litEnd = $litPtr + $litSize;
            if ($bp >= $blockEnd) {
                throw new DecodeException('Corrupted Zstandard block (no sequences section).');
            }

            // 2. Parse Sequences Section Header
            $seqByte0 = \ord($src[$bp++]);
            if ($seqByte0 < 128) {
                $seqCount = $seqByte0;
            } elseif ($seqByte0 < 255) {
                $seqCount = (($seqByte0 - 128) << 8) | \ord($src[$bp++]);
            } else {
                $seqCount = \ord($src[$bp]) + (\ord($src[$bp + 1]) << 8) + 0x7F00;
                $bp += 2;
            }

            if ($seqCount > 0) {
                $modes = \ord($src[$bp++]);
                $llMode = ($modes >> 6) & 3;
                $ofMode = ($modes >> 4) & 3;
                $mlMode = ($modes >> 2) & 3;

                // Resolve LL table (mode 3: the table of the block before)
                if ($llMode === 0) {
                    $curDecLL = self::$decLL;
                    $curLogLL = 6;
                } elseif ($llMode === 1) {
                    $curDecLL = [\ord($src[$bp++]) << 24];
                    $curLogLL = 0;
                } elseif ($llMode === 2) {
                    [$curDecLL, $curLogLL, $bp] = self::readFseTable($src, $bp, 35, 9);
                }

                // Resolve OF table
                if ($ofMode === 0) {
                    $curDecOF = self::$decOF;
                    $curLogOF = 5;
                } elseif ($ofMode === 1) {
                    $curDecOF = [\ord($src[$bp++]) << 24];
                    $curLogOF = 0;
                } elseif ($ofMode === 2) {
                    [$curDecOF, $curLogOF, $bp] = self::readFseTable($src, $bp, 31, 8);
                }

                // Resolve ML table
                if ($mlMode === 0) {
                    $curDecML = self::$decML;
                    $curLogML = 6;
                } elseif ($mlMode === 1) {
                    $curDecML = [\ord($src[$bp++]) << 24];
                    $curLogML = 0;
                } elseif ($mlMode === 2) {
                    [$curDecML, $curLogML, $bp] = self::readFseTable($src, $bp, 52, 9);
                }
                if ($bp >= $blockEnd) {
                    throw new DecodeException('Corrupted Zstandard sequences section.');
                }

                // -----------------------------------------------------------------
                // 3. Initialize Backward Bitstream Reader
                // -----------------------------------------------------------------
                $bitEnd = $blockEnd - 1;
                $lastByte = \ord($src[$bitEnd]);
                if ($lastByte === 0) {
                    throw new DecodeException('Corrupted Zstd sequence bitstream (0 padding byte).');
                }

                // Find highest set bit (sentinel bit) in $lastByte
                $highBit = 7;
                while (($lastByte & (1 << $highBit)) === 0) {
                    --$highBit;
                }

                // A window of up to 56 bits in $bitContainer, read backward from $bitEnd; its low $bitsAvailable bits are unread
                $bitContainer = $lastByte & ((1 << $highBit) - 1);
                $bitsAvailable = $highBit;
                $bitCursor = $bitEnd - 1;

                while ($bitsAvailable <= 48 && $bitCursor >= $bp) {
                    $bitContainer = ($bitContainer << 8) | \ord($src[$bitCursor--]);
                    $bitsAvailable += 8;
                }

                // Read initial FSE states: LL, OF, ML
                $bitsAvailable -= $curLogLL;
                $stateLL = ($bitContainer >> $bitsAvailable) & ((1 << $curLogLL) - 1);

                $bitsAvailable -= $curLogOF;
                $stateOF = ($bitContainer >> $bitsAvailable) & ((1 << $curLogOF) - 1);

                $bitsAvailable -= $curLogML;
                $stateML = ($bitContainer >> $bitsAvailable) & ((1 << $curLogML) - 1);

                // -----------------------------------------------------------------
                // 4. Hot Sequence Execution Loop
                // -----------------------------------------------------------------
                $lastSeq = $seqCount - 1;
                for ($s = 0; $s < $seqCount; ++$s) {
                    // Refill the register from the backward stream
                    // Refill the register from the backward stream. On PHP 8.4 and 8.5 the tracing JIT miscompiles these refill loops (wrong values
                    // read from the register afterwards; php-src issue linked in the README), so there the bytes are appended with one 8-byte read
                    // instead. PHP_VERSION_ID is a compile-time constant: only one branch is compiled.
                    if (\PHP_VERSION_ID >= 80400) {
                        if ($bitsAvailable <= 48 && $bitCursor >= $bp) {
                            $k = (56 - $bitsAvailable) >> 3;
                            if ($bitCursor - 7 >= $bp) {
                                $w = \unpack('P', $src, $bitCursor - 7)[1];
                                $bitContainer = ($bitContainer << ($k << 3)) | (($w >> ((8 - $k) << 3)) & ((1 << ($k << 3)) - 1));
                            } else {
                                $k = \min($k, $bitCursor - $bp + 1);
                                $bitContainer = ($bitContainer << ($k << 3)) | \unpack('P', \substr($src, $bitCursor - $k + 1, $k) . "\0\0\0\0\0\0\0")[1];
                            }
                            $bitCursor -= $k;
                            $bitsAvailable += $k << 3;
                        }
                    } else {
                        while ($bitsAvailable <= 48 && $bitCursor >= $bp) {
                            $bitContainer = ($bitContainer << 8) | \ord($src[$bitCursor--]);
                            $bitsAvailable += 8;
                        }
                    }

                    // Decode codes from current FSE states: entry = (symbol << 24) | (nbBits << 16) | base
                    $entryLL = $curDecLL[$stateLL];
                    $entryML = $curDecML[$stateML];
                    $entryOF = $curDecOF[$stateOF];

                    $llCode = $entryLL >> 24;
                    $mlCode = $entryML >> 24;
                    $ofCode = $entryOF >> 24;

                    // Read extra bits: OF_extra, ML_extra, LL_extra
                    if ($ofCode > 0) {
                        $bitsAvailable -= $ofCode;
                        $ofVal = (1 << $ofCode) | (($bitContainer >> $bitsAvailable) & ((1 << $ofCode) - 1));
                        if ($ofCode > 16) {
                            // (a long offset: the lengths' extra bits may not be in the register any more)
                            if (\PHP_VERSION_ID >= 80400) {
                                if ($bitsAvailable <= 48 && $bitCursor >= $bp) {
                                    $k = (56 - $bitsAvailable) >> 3;
                                    if ($bitCursor - 7 >= $bp) {
                                        $w = \unpack('P', $src, $bitCursor - 7)[1];
                                        $bitContainer = ($bitContainer << ($k << 3)) | (($w >> ((8 - $k) << 3)) & ((1 << ($k << 3)) - 1));
                                    } else {
                                        $k = \min($k, $bitCursor - $bp + 1);
                                        $bitContainer = ($bitContainer << ($k << 3)) | \unpack('P', \substr($src, $bitCursor - $k + 1, $k) . "\0\0\0\0\0\0\0")[1];
                                    }
                                    $bitCursor -= $k;
                                    $bitsAvailable += $k << 3;
                                }
                            } else {
                                while ($bitsAvailable <= 48 && $bitCursor >= $bp) {
                                    $bitContainer = ($bitContainer << 8) | \ord($src[$bitCursor--]);
                                    $bitsAvailable += 8;
                                }
                            }
                        }
                    } else {
                        $ofVal = 1;
                    }

                    $mlBits = $mlBitsArr[$mlCode];
                    if ($mlBits > 0) {
                        $bitsAvailable -= $mlBits;
                        $mLen = $mlBaseArr[$mlCode] + (($bitContainer >> $bitsAvailable) & ((1 << $mlBits) - 1));
                    } else {
                        $mLen = $mlBaseArr[$mlCode];
                    }

                    $llBits = $llBitsArr[$llCode];
                    if ($llBits > 0) {
                        $bitsAvailable -= $llBits;
                        $litLen = $llBaseArr[$llCode] + (($bitContainer >> $bitsAvailable) & ((1 << $llBits) - 1));
                    } else {
                        $litLen = $llBaseArr[$llCode];
                    }

                    // Update FSE states (LL, ML, OF) for all sequences except the last one
                    if ($s < $lastSeq) {
                        if (\PHP_VERSION_ID >= 80400) {
                            if ($bitsAvailable <= 48 && $bitCursor >= $bp) {
                                $k = (56 - $bitsAvailable) >> 3;
                                if ($bitCursor - 7 >= $bp) {
                                    $w = \unpack('P', $src, $bitCursor - 7)[1];
                                    $bitContainer = ($bitContainer << ($k << 3)) | (($w >> ((8 - $k) << 3)) & ((1 << ($k << 3)) - 1));
                                } else {
                                    $k = \min($k, $bitCursor - $bp + 1);
                                    $bitContainer = ($bitContainer << ($k << 3)) | \unpack('P', \substr($src, $bitCursor - $k + 1, $k) . "\0\0\0\0\0\0\0")[1];
                                }
                                $bitCursor -= $k;
                                $bitsAvailable += $k << 3;
                            }
                        } else {
                            while ($bitsAvailable <= 48 && $bitCursor >= $bp) {
                                $bitContainer = ($bitContainer << 8) | \ord($src[$bitCursor--]);
                                $bitsAvailable += 8;
                            }
                        }

                        $nb = ($entryLL >> 16) & 0xFF;
                        $bitsAvailable -= $nb;
                        $stateLL = ($entryLL & 0xFFFF) + (($bitContainer >> $bitsAvailable) & ((1 << $nb) - 1));

                        $nb = ($entryML >> 16) & 0xFF;
                        $bitsAvailable -= $nb;
                        $stateML = ($entryML & 0xFFFF) + (($bitContainer >> $bitsAvailable) & ((1 << $nb) - 1));

                        $nb = ($entryOF >> 16) & 0xFF;
                        $bitsAvailable -= $nb;
                        $stateOF = ($entryOF & 0xFFFF) + (($bitContainer >> $bitsAvailable) & ((1 << $nb) - 1));
                    }

                    // Resolve Repeat Offsets per RFC 8878 Section 3.1.1.5
                    if ($ofVal > 3) {
                        $offset = $ofVal - 3;
                        $rep3 = $rep2;
                        $rep2 = $rep1;
                        $rep1 = $offset;
                    } elseif ($litLen === 0) {
                        if ($ofVal === 1) {
                            $offset = $rep2;
                            $rep2 = $rep1;
                            $rep1 = $offset;
                        } elseif ($ofVal === 2) {
                            $offset = $rep3;
                            $rep3 = $rep2;
                            $rep2 = $rep1;
                            $rep1 = $offset;
                        } else {
                            $offset = $rep1 - 1;
                            $rep3 = $rep2;
                            $rep2 = $rep1;
                            $rep1 = $offset;
                        }
                    } elseif ($ofVal === 1) {
                        $offset = $rep1;
                    } elseif ($ofVal === 2) {
                        $offset = $rep2;
                        $rep2 = $rep1;
                        $rep1 = $offset;
                    } else {
                        $offset = $rep3;
                        $rep3 = $rep2;
                        $rep2 = $rep1;
                        $rep1 = $offset;
                    }

                    if ($litPtr + $litLen > $litEnd || $offset > $op + $litLen || $offset < 1) {
                        throw new DecodeException('Corrupted Zstandard sequence (literals or offset out of range).');
                    }

                    // Append the literals
                    if ($litLen > 0) {
                        $dst .= \substr($lit, $litPtr, $litLen);
                        $litPtr += $litLen;
                        $op += $litLen;
                    }

                    // Append the match; an overlapping one ($offset < $mLen) repeats the last $offset bytes
                    if ($offset >= $mLen) {
                        $dst .= \substr($dst, $op - $offset, $mLen);
                    } elseif ($offset === 1) {
                        $dst .= \str_repeat($dst[$op - 1], $mLen);
                    } else {
                        $dst .= \substr(\str_repeat(\substr($dst, $op - $offset, $offset), \intdiv($mLen, $offset) + 1), 0, $mLen);
                    }
                    $op += $mLen;
                }
            }

            // The literals after the last sequence
            if ($litPtr < $litEnd) {
                $tail = $litEnd - $litPtr;
                $dst .= \substr($lit, $litPtr, $tail);
                $op += $tail;
            }

            $pos = $blockEnd;
        } while ($isLastBlock === 0);

        if ($limit >= 0 && $op > $limit) {
            throw new DecodeException("Zstandard output exceeds the limit of $limit bytes.");
        }
        if ($contentSize >= 0 && $op !== $contentSize) {
            throw new DecodeException("Zstandard frame decodes to $op bytes, its header says $contentSize.");
        }
        if ($checksumFlag !== 0) {
            // The low 32 bits of XXH64 of the content, little-endian
            if ($pos + 4 > $srcLen) {
                throw new DecodeException('Truncated Zstandard frame (checksum).');
            }
            if (\substr(\strrev(\hash('xxh64', $dst, true)), 0, 4) !== \substr($src, $pos, 4)) {
                throw new DecodeException('Zstandard content checksum does not match.');
            }
            $pos += 4;
        }

        return $dst;
    }

    /**
     * One Huffman-coded stream of literals, $src[$start .. $end), read backward from its last byte: $n symbols written into
     * $out at $op. The table is indexed by the next $log bits of the stream: its symbol (a 1-byte string) and code length.
     *
     * @param array<int, string> $symT
     * @param array<int, int> $nbT
     */
    private static function decodeHuffmanStream(string $src, int $start, int $end, int $n, array $symT, array $nbT, int $log, string &$out, int $op): void
    {
        if ($end <= $start) {
            throw new DecodeException('Corrupted Zstandard literals (empty Huffman stream).');
        }
        $lastByte = \ord($src[$end - 1]);
        if ($lastByte === 0) {
            throw new DecodeException('Corrupted Zstandard literals (0 padding byte).');
        }
        $highBit = 7;
        while (($lastByte & (1 << $highBit)) === 0) {
            --$highBit;
        }
        $c = $lastByte & ((1 << $highBit) - 1);
        $bits = $highBit;
        $cur = $end - 2;
        $mask = (1 << $log) - 1;
        $limit = $op + $n;

        // Four symbols per refill (a code is at most 11 bits: 44 of the 49 or more in the register) while seven bytes remain to refill from
        $fastLimit = $limit - 3;
        $fastStart = $start + 6;
        while ($op < $fastLimit && $cur >= $fastStart) {
            while ($bits <= 48) {
                $c = ($c << 8) | \ord($src[$cur--]);
                $bits += 8;
            }
            $i = ($c >> ($bits - $log)) & $mask;
            $out[$op++] = $symT[$i];
            $bits -= $nbT[$i];
            $i = ($c >> ($bits - $log)) & $mask;
            $out[$op++] = $symT[$i];
            $bits -= $nbT[$i];
            $i = ($c >> ($bits - $log)) & $mask;
            $out[$op++] = $symT[$i];
            $bits -= $nbT[$i];
            $i = ($c >> ($bits - $log)) & $mask;
            $out[$op++] = $symT[$i];
            $bits -= $nbT[$i];
        }

        // The rest one by one; past the start of the stream the bits are zeros (the last codes are looked up with padding)
        while ($op < $limit) {
            while ($bits < $log) {
                $c = ($c << 8) | ($cur >= $start ? \ord($src[$cur]) : 0);
                --$cur;
                $bits += 8;
            }
            $i = ($c >> ($bits - $log)) & $mask;
            $out[$op++] = $symT[$i];
            $bits -= $nbT[$i];
        }
    }

    /**
     * The same with the multi-symbol table: one Huffman-coded stream of literals, $src[$start .. $end), symbols appended to
     * $out until it is $target bytes long. $fastSym / $fastNb, indexed by the next $fastLog bits of the stream, give one or more
     * symbols (a string) and the bits they take; $symT / $nbT, indexed by the next $log bits, exactly one symbol.
     *
     * @param array<int, string> $fastSym
     * @param array<int, int> $fastNb
     * @param array<int, string> $symT
     * @param array<int, int> $nbT
     */
    private static function decodeHuffmanStreamWide(string $src, int $start, int $end, int $target, array $fastSym, array $fastNb, int $fastLog, array $symT, array $nbT, int $log, string &$out): void
    {
        if ($end <= $start) {
            throw new DecodeException('Corrupted Zstandard literals (empty Huffman stream).');
        }
        $lastByte = \ord($src[$end - 1]);
        if ($lastByte === 0) {
            throw new DecodeException('Corrupted Zstandard literals (0 padding byte).');
        }
        $highBit = 7;
        while (($lastByte & (1 << $highBit)) === 0) {
            --$highBit;
        }
        $c = $lastByte & ((1 << $highBit) - 1);
        $bits = $highBit;
        $cur = $end - 2;

        // While seven bytes remain to refill from, every bit in the register (49 or more after a refill) is the stream's:
        // lookups of $fastLog bits, each giving as many symbols as its index holds
        $fastMask = (1 << $fastLog) - 1;
        $fastStart = $start + 6;
        while ($cur >= $fastStart) {
            while ($bits <= 48) {
                $c = ($c << 8) | \ord($src[$cur--]);
                $bits += 8;
            }
            $i = ($c >> ($bits - $fastLog)) & $fastMask;
            $out .= $fastSym[$i];
            $bits -= $fastNb[$i];
            $i = ($c >> ($bits - $fastLog)) & $fastMask;
            $out .= $fastSym[$i];
            $bits -= $fastNb[$i];
            $i = ($c >> ($bits - $fastLog)) & $fastMask;
            $out .= $fastSym[$i];
            $bits -= $fastNb[$i];
            $i = ($c >> ($bits - $fastLog)) & $fastMask;
            $out .= $fastSym[$i];
            $bits -= $fastNb[$i];
        }

        // The end of the stream symbol by symbol; past its start the bits are zeros (the last codes are looked up with padding)
        $mask = (1 << $log) - 1;
        while (\strlen($out) < $target) {
            while ($bits < $log) {
                $c = ($c << 8) | ($cur >= $start ? \ord($src[$cur]) : 0);
                --$cur;
                $bits += 8;
            }
            $i = ($c >> ($bits - $log)) & $mask;
            $out .= $symT[$i];
            $bits -= $nbT[$i];
        }

        // Exactly $target symbols, and every bit of the stream read
        if (\strlen($out) !== $target || $bits + (($cur - $start + 1) << 3) !== 0) {
            throw new DecodeException('Corrupted Zstandard literals (Huffman stream).');
        }
    }

    /**
     * From a one-symbol Huffman table (indexed by $log bits), the table indexed by HUF_WIDE bits that gives every symbol whose
     * code lies wholly in the index (a string of one or more bytes) and the bits they take together.
     *
     * @param array<int, string> $symT
     * @param array<int, int> $nbT
     * @return array{0: array<int, string>, 1: array<int, int>}
     */
    private static function buildHuffmanWide(array $symT, array $nbT, int $log): array
    {
        $wide = self::HUF_WIDE;
        $size = 1 << $wide;
        $mask = $size - 1;
        $shift = $wide - $log;
        $strT = [];
        $totT = [];
        for ($i = 0; $i < $size; ++$i) {
            $k = $i >> $shift;
            $str = $symT[$k];
            $tot = $nbT[$k];
            while (true) {
                $k = (($i << $tot) & $mask) >> $shift;
                $nb = $nbT[$k];
                if ($tot + $nb > $wide) {
                    break;
                }
                $str .= $symT[$k];
                $tot += $nb;
            }
            $strT[$i] = $str;
            $totT[$i] = $tot;
        }

        return [$strT, $totT];
    }

    /**
     * Reads a Huffman tree description (RFC 8878 Section 4.2.1) at $bp: the weights, direct (4 bits each) or FSE-compressed,
     * the last one implied. Returns the decoding table indexed by the next $log bits: [symbols, code lengths, $log, the position after].
     *
     * @return array{0: array<int, string>, 1: array<int, int>, 2: int, 3: int}
     */
    private static function readHuffmanTable(string $src, int $bp): array
    {
        $header = \ord($src[$bp++]);
        $weights = [];
        if ($header >= 128) {
            // Direct: $header - 127 weights of 4 bits, the first in the high half of a byte
            $n = $header - 127;
            for ($i = 0; $i < $n; $i += 2) {
                $b = \ord($src[$bp + ($i >> 1)]);
                $weights[$i] = $b >> 4;
                if ($i + 1 < $n) {
                    $weights[$i + 1] = $b & 15;
                }
            }
            $bp += ($n + 1) >> 1;
        } else {
            // FSE-compressed in $header bytes: a table description, then a backward bitstream of two interleaved states
            $end = $bp + $header;
            [$table, $log, $p] = self::readFseTable($src, $bp, 255, 6);
            if ($p >= $end) {
                throw new DecodeException('Corrupted Zstandard Huffman weights.');
            }
            $lastByte = \ord($src[$end - 1]);
            if ($lastByte === 0) {
                throw new DecodeException('Corrupted Zstandard Huffman weights (0 padding byte).');
            }
            $off = (($end - 1 - $p) << 3) + self::highBit($lastByte);   // the unread bits, counted from $p
            $off -= $log;
            $state1 = self::bitsAt($src, $p, $off, $log);
            $off -= $log;
            $state2 = self::bitsAt($src, $p, $off, $log);
            while (true) {
                $e = $table[$state1];
                $weights[] = $e >> 24;
                $nb = ($e >> 16) & 0xFF;
                $off -= $nb;
                $state1 = ($e & 0xFFFF) + self::bitsAt($src, $p, $off, $nb);
                if ($off < 0) {
                    $weights[] = $table[$state2] >> 24;
                    break;
                }
                $e = $table[$state2];
                $weights[] = $e >> 24;
                $nb = ($e >> 16) & 0xFF;
                $off -= $nb;
                $state2 = ($e & 0xFFFF) + self::bitsAt($src, $p, $off, $nb);
                if ($off < 0) {
                    $weights[] = $table[$state1] >> 24;
                    break;
                }
                if (\count($weights) > 255) {
                    throw new DecodeException('Corrupted Zstandard Huffman weights (too many).');
                }
            }
            $bp = $end;
        }

        // The last weight completes the sum of 2^(weight - 1) to a power of two
        $n = \count($weights);
        $sum = 0;
        foreach ($weights as $w) {
            if ($w > 11) {
                throw new DecodeException('Corrupted Zstandard Huffman weights (a weight above 11).');
            }
            if ($w > 0) {
                $sum += 1 << ($w - 1);
            }
        }
        if ($sum === 0 || $n > 255) {
            throw new DecodeException('Corrupted Zstandard Huffman weights.');
        }
        $log = self::highBit($sum) + 1;
        $left = (1 << $log) - $sum;
        $lastWeight = self::highBit($left) + 1;
        if ((1 << ($lastWeight - 1)) !== $left || $log > 11) {
            throw new DecodeException('Corrupted Zstandard Huffman weights (not a complete code).');
        }
        $weights[$n] = $lastWeight;

        // A symbol of weight w has a code of $log + 1 - w bits and 2^(w - 1) entries; the entries go by weight, then by symbol
        $rank = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
        foreach ($weights as $w) {
            ++$rank[$w];
        }
        $next = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
        $at = 0;
        for ($w = 1; $w <= $log; ++$w) {
            $next[$w] = $at;
            $at += $rank[$w] << ($w - 1);
        }
        $size = 1 << $log;
        $symT = \array_fill(0, $size, "\x00");
        $nbT = \array_fill(0, $size, 0);
        foreach ($weights as $sym => $w) {
            if ($w === 0) {
                continue;
            }
            $len = 1 << ($w - 1);
            $chr = \chr($sym);
            $nb = $log + 1 - $w;
            $from = $next[$w];
            for ($j = 0; $j < $len; ++$j) {
                $symT[$from + $j] = $chr;
                $nbT[$from + $j] = $nb;
            }
            $next[$w] = $from + $len;
        }

        return [$symT, $nbT, $log, $bp];
    }

    /**
     * $n bits (at most 16) at bit $off of the little-endian bitstream that starts at byte $base; bits before the stream (a negative
     * $off) are zeros.
     */
    private static function bitsAt(string $src, int $base, int $off, int $n): int
    {
        $shift = 0;
        if ($off < 0) {
            $shift = -$off;
            $n += $off;
            $off = 0;
        }
        if ($n <= 0) {
            return 0;
        }
        $p = $base + ($off >> 3);
        $v = \ord($src[$p] ?? "\x00") | (\ord($src[$p + 1] ?? "\x00") << 8) | (\ord($src[$p + 2] ?? "\x00") << 16);
        return (($v >> ($off & 7)) & ((1 << $n) - 1)) << $shift;
    }

    /** floor(log2($v)) for $v >= 1 */
    private static function highBit(int $v): int
    {
        $n = 0;
        while ($v > 1) {
            $v >>= 1;
            ++$n;
        }
        return $n;
    }

    /**
     * Reads an FSE table description (RFC 8878 Section 4.1.1) at $bp: the accuracy log and the normalised counts of the symbols
     * 0 .. $maxSymbol. Returns [the decoding table, the accuracy log, the position after (the description ends on a byte)].
     *
     * @return array{0: array<int, int>, 1: int, 2: int}
     */
    private static function readFseTable(string $src, int $bp, int $maxSymbol, int $maxLog): array
    {
        $tableLog = self::bitsAt($src, $bp, 0, 4) + 5;
        if ($tableLog > $maxLog) {
            throw new DecodeException('Corrupted Zstandard FSE table (accuracy log ' . $tableLog . ').');
        }
        $bit = 4;
        $remaining = 1 << $tableLog;
        $norm = [];
        $sym = 0;

        while ($remaining > 0 && $sym <= $maxSymbol) {
            // A count takes highBit(remaining + 1) + 1 bits, one less for the small values
            $nbBits = self::highBit($remaining + 1) + 1;
            $val = self::bitsAt($src, $bp, $bit, $nbBits);
            $bit += $nbBits;
            $lowerMask = (1 << ($nbBits - 1)) - 1;
            $threshold = (1 << $nbBits) - 1 - ($remaining + 1);
            if (($val & $lowerMask) < $threshold) {
                --$bit;
                $val &= $lowerMask;
            } elseif ($val > $lowerMask) {
                $val -= $threshold;
            }

            // The count is the value - 1; -1 is "less than one": it takes one state
            $count = $val - 1;
            $remaining -= $count < 0 ? 1 : $count;
            $norm[$sym++] = $count;

            if ($count === 0) {
                // Zeros are followed by 2-bit repeat counts; 3 means another count follows
                do {
                    $repeat = self::bitsAt($src, $bp, $bit, 2);
                    $bit += 2;
                    for ($i = 0; $i < $repeat; ++$i) {
                        $norm[$sym++] = 0;
                    }
                } while ($repeat === 3);
            }
        }
        if ($remaining !== 0 || $sym > $maxSymbol + 1) {
            throw new DecodeException('Corrupted Zstandard FSE table.');
        }

        return [self::buildFseDecodeTable($norm, $tableLog), $tableLog, $bp + (($bit + 7) >> 3)];
    }

    /**
     * Builds a flat FSE Decoding table per RFC 8878 Section 4.1.1.
     * Packed integer format: (symbol << 24) | (nbBits << 16) | nextStateBase
     *
     * @param array<int, int> $norm
     * @return array<int, int>
     */
    private static function buildFseDecodeTable(array $norm, int $tableLog): array
    {
        $tableSize = 1 << $tableLog;
        $tableMask = $tableSize - 1;
        $step = ($tableSize >> 1) + ($tableSize >> 3) + 3;

        $symbolList = \array_fill(0, $tableSize, 0);
        $stateDesc = [];

        // The "less than one" symbols take the last states
        $highThreshold = $tableSize - 1;
        $numSyms = \count($norm);
        for ($s = 0; $s < $numSyms; ++$s) {
            if ($norm[$s] === -1) {
                $symbolList[$highThreshold--] = $s;
                $stateDesc[$s] = 1;
            }
        }

        // The others are spread over the remaining states
        $pos = 0;
        for ($s = 0; $s < $numSyms; ++$s) {
            $v = $norm[$s];
            if ($v > 0) {
                $stateDesc[$s] = $v;
                for ($i = 0; $i < $v; ++$i) {
                    $symbolList[$pos] = $s;
                    do {
                        $pos = ($pos + $step) & $tableMask;
                    } while ($pos > $highThreshold);
                }
            }
        }

        $table = [];
        for ($u = 0; $u < $tableSize; ++$u) {
            $s = $symbolList[$u];
            $nextState = $stateDesc[$s]++;
            $nbBits = $tableLog - self::highBit($nextState);
            $newStateBase = ($nextState << $nbBits) - $tableSize;
            $table[$u] = ($s << 24) | ($nbBits << 16) | $newStateBase;
        }

        return $table;
    }

    /**
     * Builds the FSE encoding tables of a distribution (FSE_buildCTable): [the next state by (state >> nbBits) + deltaFind[symbol],
     * deltaNb per symbol (nbBits = (state + deltaNb) >> 16), deltaFind per symbol]. States are numbered tableSize .. 2 * tableSize - 1.
     *
     * @param array<int, int> $norm
     * @return array{0: array<int, int>, 1: array<int, int>, 2: array<int, int>}
     */
    private static function buildFseEncodeTable(array $norm, int $tableLog): array
    {
        $tableSize = 1 << $tableLog;
        $tableMask = $tableSize - 1;
        $step = ($tableSize >> 1) + ($tableSize >> 3) + 3;

        $tableSymbol = \array_fill(0, $tableSize, 0);
        $cumul = [];
        $total = 0;
        $highThreshold = $tableSize - 1;
        $numSyms = \count($norm);

        for ($s = 0; $s < $numSyms; ++$s) {
            $cumul[$s] = $total;
            $v = $norm[$s];
            if ($v === -1) {
                $total += 1;
                $tableSymbol[$highThreshold--] = $s;
            } elseif ($v > 0) {
                $total += $v;
            }
        }

        $pos = 0;
        for ($s = 0; $s < $numSyms; ++$s) {
            $v = $norm[$s];
            if ($v > 0) {
                for ($i = 0; $i < $v; ++$i) {
                    $tableSymbol[$pos] = $s;
                    do {
                        $pos = ($pos + $step) & $tableMask;
                    } while ($pos > $highThreshold);
                }
            }
        }

        // The states of each symbol, in the order of the table
        $stateTable = \array_fill(0, $tableSize, 0);
        for ($u = 0; $u < $tableSize; ++$u) {
            $s = $tableSymbol[$u];
            $stateTable[$cumul[$s]++] = $tableSize + $u;
        }

        $deltaNb = [];
        $deltaFind = [];
        $total = 0;
        for ($s = 0; $s < $numSyms; ++$s) {
            $v = $norm[$s];
            if ($v === 0) {
                $deltaNb[$s] = (($tableLog + 1) << 16) - $tableSize;
                $deltaFind[$s] = 0;
            } elseif ($v === -1 || $v === 1) {
                $deltaNb[$s] = ($tableLog << 16) - $tableSize;
                $deltaFind[$s] = $total - 1;
                $total += 1;
            } else {
                $maxBitsOut = $tableLog - self::highBit($v - 1);
                $deltaNb[$s] = ($maxBitsOut << 16) - ($v << $maxBitsOut);
                $deltaFind[$s] = $total - $v;
                $total += $v;
            }
        }

        return [$stateTable, $deltaNb, $deltaFind];
    }

    private static function getLLCodeSlow(int $val): int
    {
        for ($s = 35; $s >= 26; --$s) {
            if ($val >= self::LL_BASE[$s]) {
                return $s;
            }
        }
        return 25;
    }

    private static function getMLCodeSlow(int $val): int
    {
        for ($s = 52; $s >= 43; --$s) {
            if ($val >= self::ML_BASE[$s]) {
                return $s;
            }
        }
        return 42;
    }
}

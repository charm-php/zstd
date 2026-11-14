<?php

declare(strict_types=1);

namespace Charm\Zstd\Tests;

use Charm\Zstd\Zstd;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The entropy coding of the compressor: Huffman-coded literals (their tree, one and four streams, the size formats), RLE and raw
 * literals chosen by size, and the sequence tables (predefined, RLE, FSE-compressed). Every case is decoded by this package and,
 * when the tool is on PATH, by `zstd -d`.
 */
final class EntropyTest extends TestCase
{
    private static function literals(string $lit): string
    {
        $m = new \ReflectionMethod(Zstd::class, 'encodeLiterals');
        return $m->invoke(null, $lit, strlen($lit));
    }

    private static function private(string $name, mixed ...$args): mixed
    {
        return (new \ReflectionMethod(Zstd::class, $name))->invoke(null, ...$args);
    }

    /** A frame whose only block is the literals section plus "no sequences": decodes to $lit. */
    private static function frameOf(string $section, int $n): string
    {
        $size = strlen($section) + 1;
        $bh = ($size << 3) | (2 << 1) | 1;
        return "\x28\xB5\x2F\xFD\xA0" . pack('V', $n) . chr($bh & 0xFF) . chr(($bh >> 8) & 0xFF) . chr(($bh >> 16) & 0xFF) . $section . "\x00";
    }

    private function assertDecodes(string $lit, string $section, string $label): void
    {
        $frame = self::frameOf($section, strlen($lit));
        $this->assertTrue($lit === Zstd::decompress($frame), "$label: this decoder returned other bytes");
        if (Cli::available()) {
            $this->assertTrue($lit === Cli::decompress($frame), "$label: zstd -d returned other bytes (or refused)");
        }
    }

    /** @return array{int, int} literals block type, size format */
    private static function type(string $section): array
    {
        $b = ord($section[0]);
        return [$b & 3, ($b >> 2) & 3];
    }

    /** Bytes of $n literals from the given symbol weights (symbol => weight), shuffled with a fixed seed. */
    private static function weighted(int $n, array $weights, int $seed): string
    {
        mt_srand($seed);
        $total = array_sum($weights);
        $out = '';
        for ($i = 0; $i < $n; $i++) {
            $r = mt_rand(0, $total - 1);
            foreach ($weights as $sym => $w) {
                if ($r < $w) {
                    $out .= chr($sym);
                    break;
                }
                $r -= $w;
            }
        }
        return $out;
    }

    /** @return array<string, array{string, int}> name => [literals, expected type (0 raw, 1 RLE, 2 Huffman)] */
    public static function distributions(): array
    {
        $fib = [1, 1];
        for ($i = 2; $i < 22; $i++) {
            $fib[$i] = $fib[$i - 1] + $fib[$i - 2];
        }
        $fibLits = '';
        foreach ($fib as $sym => $count) {
            $fibLits .= str_repeat(chr(65 + $sym), $count);
        }
        $fibLits = str_shuffle_seeded($fibLits, 3);
        $uniform = '';
        for ($i = 0; $i < 10; $i++) {
            for ($b = 0; $b < 256; $b++) {
                $uniform .= chr($b);
            }
        }
        $high = [];
        for ($b = 0; $b < 256; $b++) {
            $high[$b] = max(1, (int) (4000 / (1 + $b)));
        }
        return [
            '1 distinct byte' => [str_repeat('a', 600), 1],
            '2 distinct bytes, 50/50' => [self::weighted(600, [10 => 1, 200 => 1], 1), 2],
            '2 distinct bytes, 95/5' => [self::weighted(1500, [0 => 95, 255 => 5], 2), 2],
            '3 distinct bytes' => [self::weighted(500, [1 => 6, 2 => 3, 3 => 1], 3), 2],
            '4 distinct bytes' => [self::weighted(700, [1 => 8, 2 => 4, 3 => 2, 4 => 1], 4), 2],
            '4 uniform bytes (2 bits each: Huffman cannot gain)' => [self::weighted(400, [1 => 1, 2 => 1, 3 => 1, 4 => 1], 5), 2],
            'all 256 byte values uniformly' => [str_shuffle_seeded($uniform, 7), 0],
            'near-uniform random bytes' => [self::weighted(5000, array_fill(0, 256, 1), 8), 0],
            'fibonacci frequencies (optimal code deeper than 11 bits)' => [$fibLits, 2],
            'bytes up to 255 with a long tail (weights need the FSE form)' => [self::weighted(30000, $high, 9), 2],
            'ASCII text letters' => [self::weighted(5000, [32 => 180, 101 => 120, 116 => 90, 97 => 80, 111 => 75, 105 => 70, 110 => 65, 115 => 60, 104 => 55, 114 => 50, 100 => 35, 108 => 33, 117 => 25, 99 => 22], 10), 2],
            'a symbol above 128 only (direct weights impossible)' => [self::weighted(800, [200 => 5, 201 => 3, 250 => 1], 11), 2],
        ];
    }

    #[DataProvider('distributions')]
    public function testLiteralDistribution(string $lit, int $expectedType): void
    {
        $section = self::literals($lit);
        $this->assertSame($expectedType, self::type($section)[0], 'literals block type');
        $this->assertDecodes($lit, $section, 'distribution');
        $this->assertLessThanOrEqual(strlen($lit) + 3, strlen($section), 'never more than raw');
    }

    public function testCodeLengthsAreLimitedAndComplete(): void
    {
        $fib = [1, 1];
        for ($i = 2; $i < 24; $i++) {
            $fib[$i] = $fib[$i - 1] + $fib[$i - 2];
        }
        $counts = [];
        foreach ($fib as $sym => $c) {
            $counts[100 + $sym] = $c;
        }
        $lens = self::private('huffmanLengths', $counts, 11);
        $this->assertLessThanOrEqual(11, max($lens));
        $kraft = 0.0;
        foreach ($lens as $l) {
            $kraft += 2 ** -$l;
        }
        $this->assertSame(1.0, $kraft, 'the lengths do not make a complete code');
        // unlimited, the rarest symbols would be 23 bits deep
        $this->assertGreaterThan(11, max(self::private('huffmanLengths', $counts, 30)));
        // a skewed alphabet: more frequent symbols never have longer codes
        $this->assertLessThanOrEqual($lens[100], $lens[123]);
    }

    /** @return array<string, array{int}> */
    public static function sizes(): array
    {
        $c = [];
        foreach ([31, 32, 33, 62, 63, 255, 256, 257, 1022, 1023, 1024, 1025, 4095, 4096, 16383, 16384, 16385, 65535, 65536, 131071, 131072] as $n) {
            $c["$n literals"] = [$n];
        }
        return $c;
    }

    #[DataProvider('sizes')]
    public function testLiteralSectionSizeFormats(int $n): void
    {
        $lit = self::weighted($n, [32 => 300, 101 => 120, 116 => 90, 97 => 80, 111 => 75, 105 => 70, 110 => 65, 10 => 20, 65 => 4, 90 => 1], $n);
        $section = self::literals($lit);
        [$type, $format] = self::type($section);
        $this->assertDecodes($lit, $section, "$n literals");
        if ($n < 32) {
            $this->assertSame(0, $type);
            return;
        }
        $this->assertSame(2, $type, 'compressible literals are Huffman-coded');
        // 1 stream below 256 literals (format 0); 4 streams above: format 1 up to 1023 literals, 2 up to 16383, 3 above
        $expected = $n < 256 ? 0 : ($n <= 1023 ? 1 : ($n <= 16383 ? 2 : 3));
        $this->assertSame($expected, $format, "size format of $n literals");
        // regenerated size as the header says it
        $h = unpack('V', substr($section, 0, 4) . "\0")[1];
        $regen = match ($format) {
            0, 1 => ($h >> 4) & 0x3FF,
            2 => ($h >> 4) & 0x3FFF,
            default => (int) (unpack('P', substr($section, 0, 5) . "\0\0\0")[1] >> 4 & 0x3FFFF),
        };
        $this->assertSame($n, $regen);
    }

    public function testBlockOfLiteralsOnlyHasNoSequences(): void
    {
        $in = Inputs::get('lits');
        $zst = Zstd::compress($in);
        $info = Frame::firstBlock($zst);
        $this->assertSame(2, $info['blockType'], 'a compressed block');
        $this->assertSame(0, $info['sequences'], 'no sequences');
        $this->assertSame(2, $info['literalsType'], 'Huffman literals');
        $this->assertLessThan(strlen($in) * 0.7, strlen($zst));
        $this->assertTrue($in === Zstd::decompress($zst));
        if (Cli::available()) {
            $this->assertTrue($in === Cli::decompress($zst));
        }
    }

    public function testLiteralsOnlyBlockOfExactly128KiB(): void
    {
        $in = Inputs::noRepeats(131072, 31, 100);
        $zst = Zstd::compress($in);
        $info = Frame::firstBlock($zst);
        $this->assertSame(0, $info['sequences']);
        $this->assertSame(131072, $info['regenerated']);
        $this->assertSame(3, $info['literalsFormat']);
        $this->assertLessThan(131072, strlen($zst));
        $this->assertTrue($in === Zstd::decompress($zst));
        if (Cli::available()) {
            $this->assertTrue($in === Cli::decompress($zst));
        }
    }

    public function testRandomBytesStayRawAndTextGetsHuffmanLiterals(): void
    {
        mt_srand(2);
        $r = '';
        for ($i = 0; $i < 100000; $i++) {
            $r .= chr(mt_rand(0, 255));
        }
        $this->assertSame(0, Frame::firstBlock(Zstd::compress($r))['blockType'], 'incompressible data is a raw block');
        $text = Zstd::compress(Inputs::text(100000, 3));
        $this->assertSame(2, Frame::firstBlock($text)['literalsType']);
    }

    public function testSequenceTablesAreChosenPerBlock(): void
    {
        $text = Frame::firstBlock(Zstd::compress(Inputs::text(100000, 4)));
        $this->assertGreaterThan(100, $text['sequences']);
        $this->assertContains(2, $text['modes'], 'FSE-compressed tables for a text block');
        // a period-4 pattern: every sequence has the same codes, so the tables are RLE
        mt_srand(6);
        $t = 'RSTUVWXY';
        $c = 0;
        for ($i = 0; $i < 3000; $i++) {
            $c = ($c + 1 + mt_rand(0, 249)) % 256;
            $t .= chr($c) . 'RSTUVWXY';
        }
        $rle = Frame::firstBlock(Zstd::compress($t));
        $this->assertGreaterThan(2000, $rle['sequences']);
        $this->assertContains(2, $rle['modes'], 'a first sequence that differs from the other 2999 makes the code worth describing');
        // all codes the same: RLE (one byte for the table); a few the same: the predefined table is cheaper
        $mode = self::private('chooseSequenceTable', array_fill(0, 500, 5), 500, 9, [4, 3, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 1, 1, 1], 6, [[], [], []]);
        $this->assertSame([1, "\x05", null, 0], $mode);
        $this->assertSame(0, self::private('chooseSequenceTable', [0, 0], 2, 9, [4, 3, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 1, 1, 1], 6, [[], [], []])[0]);
        $this->assertTrue($t === Zstd::decompress(Zstd::compress($t)));
        if (Cli::available()) {
            $this->assertTrue($t === Cli::decompress(Zstd::compress($t)));
        }
        // few sequences: the predefined tables are cheaper than a description
        $few = Frame::firstBlock(Zstd::compress(Inputs::text(300, 5) . Inputs::text(300, 5)));
        $this->assertContains(0, $few['modes']);
    }

    /** @return array<string, array{string}> */
    public static function stress(): array
    {
        $cases = [];
        foreach ([
            'alphabet 1' => 1, 'alphabet 2' => 2, 'alphabet 3' => 3, 'alphabet 4' => 4, 'alphabet 9' => 9, 'alphabet 64' => 64, 'alphabet 200' => 200, 'alphabet 256' => 256,
        ] as $name => $k) {
            mt_srand($k);
            $s = '';
            for ($i = 0; $i < 70000; $i++) {
                $s .= chr(mt_rand(0, 99) < 50 ? 0 : mt_rand(0, $k - 1));
            }
            $cases[$name] = [$s];
        }
        $cases['skewed 24'] = [Inputs::get('skew')];
        $cases['lits'] = [Inputs::get('lits')];
        $cases['text'] = [Inputs::text(300000, 12)];
        return $cases;
    }

    #[DataProvider('stress')]
    public function testCompressRoundTrip(string $in): void
    {
        $zst = Zstd::compress($in);
        $this->assertTrue($in === Zstd::decompress($zst));
        if (Cli::available()) {
            $this->assertTrue($in === Cli::decompress($zst), 'zstd -d');
        }
    }

    public function testCompressedOutputBeatsTheOldFormatOnText(): void
    {
        // raw literals and predefined tables gave 0.52 of the input on this text; entropy coding must stay well below
        $text = Inputs::text(200000, 77);
        $this->assertLessThan(strlen($text) * 0.45, strlen(Zstd::compress($text)));
    }

    public function testNormalizedCountsSumToTheTable(): void
    {
        foreach ([[5, 5, 5, 85], [1, 1, 1, 1, 1, 1, 1, 1, 100], [50, 50], [3, 1, 1, 1]] as $counts) {
            $hist = array_filter($counts);
            foreach ([5, 6, 7] as $log) {
                $norm = self::private('fseNormalize', $hist, array_sum($hist), count($hist) - 1, $log);
                $this->assertSame(1 << $log, array_sum($norm));
                $this->assertGreaterThan(0, min($norm));
            }
        }
    }
}

function str_shuffle_seeded(string $s, int $seed): string
{
    mt_srand($seed);
    $a = str_split($s);
    for ($i = count($a) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$a[$i], $a[$j]] = [$a[$j], $a[$i]];
    }
    return implode('', $a);
}

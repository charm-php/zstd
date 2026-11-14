<?php

declare(strict_types=1);

namespace Charm\Zstd\Tests;

use Charm\Zstd\{Decoder, Encoder, Zstd};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The incremental Decoder: the same result as Zstd::decompress() whatever the chunking, the same strictness, bounded memory. */
final class DecoderTest extends TestCase
{
    private const MAGIC = "\x28\xB5\x2F\xFD";

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . "/fixtures/$name.zst");
    }

    /**
     * Cuts $data into chunks. Patterns: one (a single chunk), bytes (1 byte each), 64k, random (sizes 1 to 70,000 from a seeded generator),
     * tiny (sizes 1 to 9), empties (random sizes with empty chunks between).
     *
     * @return list<string>
     */
    public static function split(string $data, string $pattern, int $seed = 1): array
    {
        $n = strlen($data);
        if ($pattern === 'one') {
            return [$data];
        }
        $chunks = [];
        mt_srand($seed);
        for ($p = 0; $p < $n;) {
            $size = match ($pattern) {
                'bytes' => 1,
                '64k' => 65536,
                'tiny' => mt_rand(1, 9),
                'random' => mt_rand(0, 1) ? mt_rand(1, 70000) : mt_rand(1, 300),
                'empties' => mt_rand(1, 5000),
            };
            $chunks[] = substr($data, $p, $size);
            $p += $size;
            if ($pattern === 'empties') {
                $chunks[] = '';
                if (mt_rand(0, 1)) {
                    $chunks[] = '';
                }
            }
        }
        return $chunks;
    }

    /** @param iterable<string> $chunks */
    private static function stream(iterable $chunks, ?int $maxSize = null): string
    {
        $d = new Decoder($maxSize);
        $out = '';
        foreach ($chunks as $c) {
            $out .= $d->decode($c);
        }
        return $out . $d->finish();
    }

    /** @return array<string, array{string}> */
    public static function fixtures(): array
    {
        return FixtureTest::fixtures();
    }

    /** @return array<string, array{string}> */
    public static function patterns(): array
    {
        return ['one' => ['one'], 'bytes' => ['bytes'], '64k' => ['64k'], 'random' => ['random'], 'tiny' => ['tiny'], 'empties' => ['empties']];
    }

    #[DataProvider('fixtures')]
    public function testEveryFixtureThroughEverySplitPattern(string $name): void
    {
        $zst = self::fixture($name);
        $want = Zstd::decompress($zst);
        foreach (['one', 'bytes', '64k', 'random', 'tiny', 'empties'] as $pattern) {
            if ($pattern === 'bytes' && strlen($zst) > 120000) {
                $pattern = 'tiny'; // (a million calls per fixture is not what this is about)
            }
            $this->assertTrue($want === self::stream(self::split($zst, $pattern, 7)), "$name through '$pattern' chunks");
        }
    }

    public function testOneByteChunksOfALargerFixture(): void
    {
        $zst = self::fixture('level19');
        $this->assertTrue(Zstd::decompress($zst) === self::stream(self::split($zst, 'bytes')));
    }

    public function testOutputArrivesABlockAtATime(): void
    {
        // each block is returned as soon as its last input byte has been given
        $zst = self::fixture('block4096');
        $d = new Decoder();
        $got = '';
        $returned = 0;
        foreach (self::split($zst, 'bytes') as $i => $c) {
            $r = $d->decode($c);
            $got .= $r;
            if ($r !== '') {
                ++$returned;
                $this->assertLessThanOrEqual(131072, strlen($r), 'at most one block at a time');
            }
        }
        $got .= $d->finish();
        $this->assertSame(Zstd::decompress($zst), $got);
        $this->assertGreaterThan(1, $returned, 'output came before the end of the input');
    }

    public function testFinishResetsAndTheDecoderDecodesAnotherStream(): void
    {
        $d = new Decoder();
        $a = self::fixture('level3');
        $b = self::fixture('piped-check');
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(Zstd::decompress($a), $d->decode($a) . $d->finish());
            $this->assertSame(Zstd::decompress($b), $d->decode($b) . $d->finish());
        }
    }

    public function testFinishAfterFailureToo(): void
    {
        $d = new Decoder();
        $d->decode(substr(self::fixture('level3'), 0, 100));
        try {
            $d->finish();
            $this->fail('an unfinished frame is incomplete');
        } catch (\UnexpectedValueException) {
        }
        $this->assertSame(Zstd::decompress(self::fixture('level3')), $d->decode(self::fixture('level3')) . $d->finish());
    }

    public function testNoInputAtAllIsIncomplete(): void
    {
        $d = new Decoder();
        $this->expectException(\UnexpectedValueException::class);
        try {
            Zstd::decompress('');
        } catch (\UnexpectedValueException) {
            $d->decode('');
            $d->finish();
        }
    }

    public function testEmptyChunksAloneAreIncomplete(): void
    {
        $d = new Decoder();
        $d->decode('');
        $d->decode('');
        $this->expectException(\UnexpectedValueException::class);
        $d->finish();
    }

    public function testFrameWithEmptyContent(): void
    {
        $zst = self::fixture('empty');
        $this->assertSame('', self::stream(self::split($zst, 'bytes')));
        $this->assertSame('', self::stream([Zstd::compress('')]));
    }

    public function testConcatenatedAndSkippableFramesAcrossChunks(): void
    {
        $skip = self::MAGIC; // (replaced below)
        $skip = "\x5A\x2A\x4D\x18" . pack('V', 11) . 'hello world';
        $a = Zstd::compress('first frame ');
        $b = self::fixture('piped-check');
        $stream = $skip . $a . $skip . $b . $skip . $a . $skip;
        $want = Zstd::decompress($stream);
        $this->assertSame('first frame ' . Zstd::decompress($b) . 'first frame ', $want);
        foreach (['one', 'bytes', 'tiny', 'random', 'empties'] as $pattern) {
            $this->assertSame($want, self::stream(self::split($stream, $pattern, 3)), $pattern);
        }
    }

    public function testASkippableFrameIsNotHeld(): void
    {
        $d = new Decoder();
        $d->decode("\x50\x2A\x4D\x18" . pack('V', 3 << 20));
        $chunk = str_repeat('x', 1 << 20);
        memory_reset_peak_usage();
        $base = memory_get_usage();
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame('', $d->decode($chunk));
        }
        $this->assertLessThan(3 << 20, memory_get_usage() - $base, 'a skipped frame must not be buffered');
        $this->assertSame('', $d->finish());
    }

    public function testASkippableFrameOnlyIsAValidStream(): void
    {
        $stream = "\x5F\x2A\x4D\x18" . pack('V', 2) . 'ab';
        $this->assertSame('', Zstd::decompress($stream));
        $this->assertSame('', self::stream(self::split($stream, 'bytes')));
    }

    /** @return array<string, array{string}> */
    public static function badStreams(): array
    {
        $ok = Zstd::compress('hello hello hello hello');
        return [
            'bad magic' => ['not a zstd frame at all'],
            'trailing garbage' => [$ok . 'xx'],
            'trailing garbage of a whole magic' => [$ok . 'garbage!'],
            'a truncated frame' => [substr($ok, 0, -2)],
            'a truncated header' => [substr($ok, 0, 6)],
            'a magic only' => [self::MAGIC],
            'a truncated skippable frame' => ["\x50\x2A\x4D\x18" . pack('V', 10) . 'abc'],
            'a skippable header cut' => ["\x50\x2A\x4D"],
            'a dictionary id' => [self::MAGIC . "\x21" . "\x05\x00" . "\x01\x00\x00"],
            'a reserved bit' => [self::MAGIC . "\x28\x00\x01\x00\x00"],
            'a reserved block type' => [self::MAGIC . "\x20\x00" . "\x07\x00\x00"],
            'a block above 128 KiB' => [self::MAGIC . "\x00\x58" . pack('V', (131073 << 3) | 1 | (1 << 1)) . 'x'],
            'a checksum cut' => [substr(self::fixture('level3-check'), 0, -1)],
            'a wrong checksum' => [substr(self::fixture('level3-check'), 0, -1) . "\x00"],
        ];
    }

    #[DataProvider('badStreams')]
    public function testBadStreamsThrowAsDecompressDoes(string $stream): void
    {
        $threw = false;
        try {
            Zstd::decompress($stream);
        } catch (\UnexpectedValueException) {
            $threw = true;
        }
        $this->assertTrue($threw, 'decompress() accepts this stream: the test is wrong');
        foreach (['one', 'bytes', 'tiny', 'random'] as $pattern) {
            try {
                self::stream(self::split($stream, $pattern, 5));
                $this->fail("no exception for '$pattern' chunks");
            } catch (\UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testBadMagicIsRefusedAsSoonAsFourBytesAreThere(): void
    {
        $d = new Decoder();
        $d->decode('abc');
        $this->expectException(\UnexpectedValueException::class);
        $d->decode('d');
    }

    public function testChecksumMismatchIsThrownByDecode(): void
    {
        $zst = self::fixture('level3-check');
        $zst[strlen($zst) - 1] = chr(ord($zst[strlen($zst) - 1]) ^ 1);
        $d = new Decoder();
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessageMatches('/checksum/');
        $d->decode($zst);
    }

    public function testNoWarningsEscape(): void
    {
        set_error_handler(static function (int $no, string $str, string $file, int $line): never {
            throw new \ErrorException($str, 0, $no, $file, $line);
        });
        try {
            mt_srand(11);
            $zst = self::fixture('small-level3-check');
            for ($i = 0; $i < 300; $i++) {
                $bad = $zst;
                $bad[mt_rand(0, strlen($bad) - 1)] = chr(mt_rand(0, 255));
                try {
                    self::stream(self::split($bad, 'random', $i));
                } catch (\UnexpectedValueException) {
                }
            }
        } finally {
            restore_error_handler();
        }
        $this->addToAssertionCount(1);
    }

    /**
     * Damaged frames through random splits: the Decoder throws exactly when decompress() does, and returns the same bytes otherwise.
     *
     * @return array<string, array{string, int}>
     */
    public static function frames(): array
    {
        return [
            'zstd -3 with checksum' => ['small-level3-check', 1],
            'zstd -19, no checksum' => ['small-level19-nocheck', 2],
            'zstd -1 --no-compress-literals' => ['small-level1-nocheck-rawlits', 3],
            'zstd --fast=3' => ['small-fast3-nocheck', 4],
            'piped (no content size)' => ['piped-check', 5],
        ];
    }

    #[DataProvider('frames')]
    public function testDamagedFramesThrowExactlyWhenDecompressDoes(string $fixture, int $seed): void
    {
        $zst = self::fixture($fixture);
        $this->damage($zst, $seed, 1000);
    }

    public function testDamagedFramesOfOurEncoders(): void
    {
        $plain = Inputs::get('small');
        $this->damage(Zstd::compress($plain), 6, 600);
        $e = new Encoder();
        $this->damage($e->encode($plain) . $e->finish(), 7, 600);
    }

    private function damage(string $zst, int $seed, int $iterations): void
    {
        $n = strlen($zst);
        mt_srand($seed);
        $thrown = 0;
        for ($i = 0; $i < $iterations; $i++) {
            $bad = $zst;
            switch ($i % 3) {
                case 0:
                    $bad = substr($bad, 0, mt_rand(0, $n - 1));
                    break;
                case 1:
                    $p = mt_rand(0, $n - 1);
                    $bad[$p] = chr(ord($bad[$p]) ^ (1 << mt_rand(0, 7)));
                    break;
                default:
                    for ($k = mt_rand(2, 4); $k > 0; $k--) {
                        $bad[mt_rand(0, $n - 1)] = chr(mt_rand(0, 255));
                    }
            }
            try {
                $want = Zstd::decompress($bad);
            } catch (\UnexpectedValueException) {
                $want = null;
            }
            $chunks = self::split($bad, ['random', 'tiny', 'empties', 'bytes'][$i % 4], $i);
            try {
                $got = self::stream($chunks);
            } catch (\UnexpectedValueException) {
                $got = null;
                ++$thrown;
            }
            $this->assertTrue($want === $got, "damaged frame #$i (seed $seed): decompress() " . ($want === null ? 'throws' : 'returns ' . strlen($want) . ' bytes')
                . ', the Decoder ' . ($got === null ? 'throws' : 'returns ' . strlen($got) . ' bytes'));
        }
        $this->assertGreaterThan(0, $thrown);
    }

    // ------------------------------------------------------------------ $maxSize

    /** @return array<string, array{string}> */
    public static function limitFixtures(): array
    {
        $cases = [];
        foreach (['level3', 'no-content-size', 'piped', 'piped-check', 'block4096', 'mixed-level19', 'concatenated', 'skippable', 'empty'] as $n) {
            $cases[$n] = [$n];
        }
        return $cases;
    }

    #[DataProvider('limitFixtures')]
    public function testMaxSizeExactPassesAndOneLessThrows(string $name): void
    {
        $zst = self::fixture($name);
        $n = strlen(Zstd::decompress($zst));
        foreach (['one', 'tiny', 'random'] as $pattern) {
            $this->assertSame($n, strlen(self::stream(self::split($zst, $pattern, 2), $n)));
            $this->assertSame($n, strlen(self::stream(self::split($zst, $pattern, 2), $n + 1000000)));
            if ($n > 0) {
                try {
                    self::stream(self::split($zst, $pattern, 2), $n - 1);
                    $this->fail("$name through '$pattern': no exception");
                } catch (\UnexpectedValueException $e) {
                    $this->assertStringContainsString('limit', $e->getMessage());
                }
            }
        }
    }

    public function testNegativeMaxSizeIsAProgrammingError(): void
    {
        $this->expectException(\ValueError::class);
        new Decoder(-1);
    }

    public function testMaxSizeIsForTheWholeStreamAcrossFrames(): void
    {
        $a = Zstd::compress(str_repeat('a', 1000));
        $this->assertSame(2000, strlen(self::stream(self::split($a . $a, 'tiny'), 2000)));
        $this->expectException(\UnexpectedValueException::class);
        self::stream(self::split($a . $a, 'tiny'), 1999);
    }

    public function testDeclaredSizeAboveTheLimitIsRefusedByTheHeaderAlone(): void
    {
        $d = new Decoder(1000);
        $frame = self::MAGIC . "\xE0" . pack('P', 64 << 30);
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessageMatches('/declares/');
        $d->decode($frame);
    }

    private static function rleBomb(int $blocks): string
    {
        $out = self::MAGIC . "\x00\x40"; // no single segment, window descriptor: 256 KiB
        for ($i = 1; $i <= $blocks; $i++) {
            $h = (131072 << 3) | (1 << 1) | ($i === $blocks ? 1 : 0);
            $out .= chr($h & 255) . chr(($h >> 8) & 255) . chr($h >> 16) . "\0";
        }
        return $out;
    }

    public function testBombFedInSmallChunksIsRejectedQuicklyWithoutAllocatingTheOutput(): void
    {
        $bomb = self::rleBomb(512); // 64 MiB of zeros in 2 KB
        gc_collect_cycles();
        $base = memory_get_usage();
        memory_reset_peak_usage();
        $t = hrtime(true);
        $d = new Decoder(1 << 20);
        $produced = 0;
        try {
            foreach (self::split($bomb, 'tiny', 9) as $c) {
                $produced += strlen($d->decode($c));
            }
            $d->finish();
            $this->fail('no exception');
        } catch (\UnexpectedValueException $e) {
            $this->assertStringContainsString('limit', $e->getMessage());
        }
        $this->assertLessThan(1000, (hrtime(true) - $t) / 1e6, 'took long to reject the bomb');
        $this->assertLessThanOrEqual((1 << 20) + 131072, $produced, 'at most one block over the limit');
        $this->assertLessThan(4 << 20, memory_get_peak_usage() - $base);
    }

    public function testBombOfFramesIsRejectedByTheDeclaredSizes(): void
    {
        $one = Zstd::compress(str_repeat("\0", 1 << 20));
        $bomb = str_repeat($one, 64);
        $d = new Decoder(3 << 20);
        memory_reset_peak_usage();
        $base = memory_get_usage();
        $out = 0;
        try {
            foreach (self::split($bomb, 'random', 4) as $c) {
                $out += strlen($d->decode($c));
            }
            $this->fail('no exception');
        } catch (\UnexpectedValueException $e) {
            $this->assertStringContainsString('declares', $e->getMessage());
        }
        $this->assertLessThanOrEqual(3 << 20, $out);
        $this->assertLessThan(12 << 20, memory_get_peak_usage() - $base);
    }

    // ------------------------------------------------------------------ bounded memory

    /** A frame with a 1 KiB window made of $count RLE blocks of 1 KiB (the most a block may hold then): $count KiB of output. */
    private static function smallWindowFrame(int $count): string
    {
        $out = self::MAGIC . "\x00\x00"; // no single segment; window descriptor 0: 1 KiB
        for ($i = 1; $i <= $count; $i++) {
            $h = (1024 << 3) | (1 << 1) | ($i === $count ? 1 : 0);
            $out .= chr($h & 255) . chr(($h >> 8) & 255) . chr($h >> 16) . chr($i & 255);
        }
        return $out;
    }

    public function testDecodingAStreamOfMoreThan64MiBKeepsOnlyTheWindow(): void
    {
        $count = 70 * 1024; // 70 MiB of output
        $zst = self::smallWindowFrame($count);
        gc_collect_cycles();
        $base = memory_get_usage();
        memory_reset_peak_usage();
        $d = new Decoder();
        $total = 0;
        $hash = hash_init('sha256');
        foreach (str_split($zst, 2048) as $c) { // (1024 bytes of output per 4 of input: a small chunk keeps the output of one call small)
            $r = $d->decode($c);
            $total += strlen($r);
            hash_update($hash, $r);
        }
        $d->finish();
        $this->assertSame($count * 1024, $total);
        $want = hash_init('sha256');
        for ($i = 1; $i <= $count; $i++) {
            hash_update($want, str_repeat(chr($i & 255), 1024));
        }
        $this->assertSame(hash_final($want), hash_final($hash));
        $peak = memory_get_peak_usage() - $base;
        $this->assertLessThan(8 << 20, $peak, "peak memory grew by $peak bytes for 70 MiB of output");
    }

    public function testMatchesAcrossTrimmedHistoryAndBoundedMemoryWithOurEncoder(): void
    {
        // 66 MiB: pieces of generated text (matches reach back up to about 100 KB) with random bytes in between, so the ratio is about 2
        $pool = Inputs::text(100000, 3);
        $e = new Encoder();
        $zst = '';
        $hash = hash_init('sha256');
        $total = 66 << 20;
        for ($done = 0, $k = 0; $done < $total; $k++) {
            $piece = random_bytes(8000) . substr($pool, ($k * 7919) % 30000, 57536);
            hash_update($hash, $piece);
            $zst .= $e->encode($piece);
            $done += strlen($piece);
        }
        $zst .= $e->finish();

        gc_collect_cycles();
        $base = memory_get_usage();
        memory_reset_peak_usage();
        $d = new Decoder();
        $got = hash_init('sha256');
        $n = 0;
        for ($p = 0; $p < strlen($zst); $p += 65536) {
            $r = $d->decode(substr($zst, $p, 65536));
            $n += strlen($r);
            hash_update($got, $r);
        }
        $d->finish();
        $peak = memory_get_peak_usage() - $base;
        $this->assertSame($done, $n);
        $this->assertSame(hash_final($hash), hash_final($got));
        // the window is 128 KiB (trimmed in steps of 1 MiB), a 64 KiB chunk gives about 100 KB of output
        $this->assertLessThan(8 << 20, $peak, "peak memory grew by $peak bytes for " . ($done >> 20) . ' MiB of output');
    }

    public function testOffsetBeyondTheWindowIsRejectedByBothPaths(): void
    {
        // Matches that reach back 5,000 bytes, in a frame whose window descriptor is then changed to 1 KiB
        $block = Inputs::text(5000, 1);
        $plain = str_repeat($block, 40);
        $e = new Encoder();
        $zst = $e->encode($plain) . $e->finish();
        $this->assertSame($plain, self::stream([$zst]));
        $this->assertSame("\x38", $zst[5], 'the window descriptor of the Encoder: 128 KiB');
        $zst[5] = "\x00";
        try {
            Zstd::decompress($zst);
            $this->fail('decompress() accepted an offset beyond the window');
        } catch (\UnexpectedValueException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(\UnexpectedValueException::class);
        self::stream(self::split($zst, 'tiny'));
    }
}

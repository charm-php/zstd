<?php

declare(strict_types=1);

namespace Charm\Zstd\Tests;

use Charm\Zstd\Zstd;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MaxSizeTest extends TestCase
{
    private const MAGIC = "\x28\xB5\x2F\xFD";

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . "/fixtures/$name.zst");
    }

    /** @return array<string, array{string}> */
    public static function fixtures(): array
    {
        $cases = [];
        foreach (['level3', 'no-content-size', 'piped', 'piped-check', 'block4096', 'mixed-level19', 'concatenated', 'skippable', 'empty'] as $n) {
            $cases[$n] = [$n];
        }
        return $cases;
    }

    private static function plainSize(string $name): int
    {
        $m = json_decode((string) file_get_contents(__DIR__ . '/fixtures/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        return $m[$name]['bytes'];
    }

    #[DataProvider('fixtures')]
    public function testExactSizePasses(string $name): void
    {
        $n = self::plainSize($name);
        $this->assertSame($n, strlen(Zstd::decompress(self::fixture($name), $n)));
        $this->assertSame($n, strlen(Zstd::decompress(self::fixture($name), $n + 1000000)));
    }

    #[DataProvider('fixtures')]
    public function testOneByteLessThrows(string $name): void
    {
        $n = self::plainSize($name);
        if ($n === 0) {
            $this->markTestSkipped('nothing to be one byte short of');
        }
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessageMatches('/limit/');
        Zstd::decompress(self::fixture($name), $n - 1);
    }

    public function testZeroLimit(): void
    {
        $this->assertSame('', Zstd::decompress(self::fixture('empty'), 0));
        $this->expectException(\UnexpectedValueException::class);
        Zstd::decompress(Zstd::compress('x'), 0);
    }

    public function testNoLimitByDefaultAndWithNull(): void
    {
        $zst = self::fixture('level3');
        $this->assertSame(Zstd::decompress($zst), Zstd::decompress($zst, null));
    }

    public function testDeclaredSizeAboveTheLimitIsRejectedBeforeDecoding(): void
    {
        // Single segment, an 8-byte content size of 64 GiB, and then nothing: only the header can be what refuses this
        $frame = self::MAGIC . "\xE0" . pack('P', 64 << 30);
        try {
            Zstd::decompress($frame, 1000);
            $this->fail('no exception');
        } catch (\UnexpectedValueException $e) {
            $this->assertStringContainsString('declares', $e->getMessage());
        }
        // without a limit the same frame is truncated, a different failure
        try {
            Zstd::decompress($frame);
            $this->fail('no exception');
        } catch (\UnexpectedValueException $e) {
            $this->assertStringContainsString('Truncated', $e->getMessage());
        }
    }

    public function testFrameWithoutContentSizeIsCutOff(): void
    {
        // The piped fixture does not declare its size: the limit is enforced on the blocks
        $zst = self::fixture('piped');
        $this->assertSame(0, ord($zst[4]) & 0xE0);
        try {
            Zstd::decompress($zst, 1000);
            $this->fail('no exception');
        } catch (\UnexpectedValueException $e) {
            $this->assertStringContainsString('limit', $e->getMessage());
        }
    }

    /** A frame without content size made of $blocks RLE blocks of 128 KiB: $blocks * 128 KiB of output in $blocks * 4 + 8 bytes. */
    private static function rleBomb(int $blocks): string
    {
        $out = self::MAGIC . "\x00\x40"; // no single segment, window descriptor
        for ($i = 1; $i <= $blocks; $i++) {
            $h = (131072 << 3) | (1 << 1) | ($i === $blocks ? 1 : 0);
            $out .= chr($h & 255) . chr(($h >> 8) & 255) . chr($h >> 16) . "\0";
        }
        return $out;
    }

    public function testBombOfRleBlocksIsRejectedQuicklyAndWithoutAllocatingIt(): void
    {
        $bomb = self::rleBomb(512); // 64 MiB of zeros in 2 KB
        $this->assertLessThan(3000, strlen($bomb));
        $this->assertSame(64 << 20, strlen(Zstd::decompress($bomb)), 'the bomb is a valid frame');

        gc_collect_cycles();
        $base = memory_get_usage();
        memory_reset_peak_usage();
        $t = hrtime(true);
        try {
            Zstd::decompress($bomb, 1 << 20);
            $this->fail('no exception');
        } catch (\UnexpectedValueException $e) {
            $this->assertStringContainsString('limit', $e->getMessage());
        }
        $ms = (hrtime(true) - $t) / 1e6;
        $peak = memory_get_peak_usage() - $base;
        $this->assertLessThan(1000, $ms, 'took long to reject the bomb');
        // 1 MiB allowed, at most one more block (128 KiB) produced, and a copy while it is appended to: well under 4 MiB, not 64
        $this->assertLessThan(4 << 20, $peak, 'peak memory grew by ' . $peak . ' bytes');
    }

    public function testOvershootIsAtMostOneBlock(): void
    {
        // The limit falls inside the 3rd block: decoding stops after that block, whatever follows
        $bomb = self::rleBomb(512);
        memory_reset_peak_usage();
        $base = memory_get_usage();
        try {
            Zstd::decompress($bomb, 2 * 131072 + 5);
            $this->fail('no exception');
        } catch (\UnexpectedValueException) {
        }
        $this->assertLessThan(3 * 131072 * 2 + (1 << 20), memory_get_peak_usage() - $base);
        // exactly at a block boundary passes
        $this->assertSame(3 * 131072, strlen(Zstd::decompress(self::rleBomb(3), 3 * 131072)));
    }

    public function testBombOfCompressedFramesIsRejected(): void
    {
        // 64 concatenated frames of 1 MiB of zeros each, produced by compress(): 64 MiB in a few KB
        $one = Zstd::compress(str_repeat("\0", 1 << 20));
        $this->assertLessThan(2000, strlen($one));
        $bomb = str_repeat($one, 64);
        $this->assertSame(64 << 20, strlen(Zstd::decompress($bomb, 64 << 20)));

        gc_collect_cycles();
        $base = memory_get_usage();
        memory_reset_peak_usage();
        $t = hrtime(true);
        try {
            Zstd::decompress($bomb, 3 << 20);
            $this->fail('no exception');
        } catch (\UnexpectedValueException $e) {
            $this->assertStringContainsString('limit', $e->getMessage());
        }
        $this->assertLessThan(1000, (hrtime(true) - $t) / 1e6);
        $this->assertLessThan(12 << 20, memory_get_peak_usage() - $base);
    }

    public function testLimitIsForTheWholeOutputAcrossFrames(): void
    {
        $a = Zstd::compress(str_repeat('a', 1000));
        $this->assertSame(2000, strlen(Zstd::decompress($a . $a, 2000)));
        $this->expectException(\UnexpectedValueException::class);
        Zstd::decompress($a . $a, 1999);
    }

    public function testChecksumIsStillVerifiedWithALimit(): void
    {
        $zst = self::fixture('level3');
        $zst[strlen($zst) - 1] = chr(ord($zst[strlen($zst) - 1]) ^ 1);
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessageMatches('/checksum/');
        Zstd::decompress($zst, 10000000);
    }
}

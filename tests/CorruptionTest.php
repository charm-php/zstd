<?php

declare(strict_types=1);

namespace Charm\Zstd\Tests;

use Charm\Zstd\Zstd;
use PHPUnit\Framework\TestCase;

/**
 * Damaged frames (fixed seeds): truncations and bit flips. The only allowed outcomes are \UnexpectedValueException, or a returned string;
 * and when the frame has a checksum, a returned string must be the original (a changed frame never yields wrong bytes).
 */
final class CorruptionTest extends TestCase
{
    private const ITERATIONS = 1500;

    protected function setUp(): void
    {
        // Any PHP warning or notice would become an exception here (and so fail the test), unless the decoder deals with it first.
        set_error_handler(static function (int $no, string $str, string $file, int $line): never {
            throw new \ErrorException($str, 0, $no, $file, $line);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    /** @return array<string, array{string, bool, int}> fixture, has checksum, seed */
    public static function frames(): array
    {
        return [
            'zstd -3 with checksum' => ['small-level3-check', true, 1],
            'zstd -19, no checksum' => ['small-level19-nocheck', false, 2],
            'zstd -1 --no-compress-literals, no checksum' => ['small-level1-nocheck-rawlits', false, 3],
            'zstd --fast=3, no checksum' => ['small-fast3-nocheck', false, 4],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('frames')]
    public function testDamagedReferenceFrames(string $fixture, bool $checksum, int $seed): void
    {
        $this->damage((string) file_get_contents(__DIR__ . "/fixtures/$fixture.zst"), Inputs::get('small'), $checksum, $seed);
    }

    public function testDamagedFramesFromOurCompressor(): void
    {
        $plain = Inputs::get('small');
        $this->damage(Zstd::compress($plain), $plain, false, 5);
    }

    public function testDamagedMixedFrameWithRawAndRleBlocks(): void
    {
        $zst = (string) file_get_contents(__DIR__ . '/fixtures/mixed-block4096-check.zst');
        // (a 266 KB frame: fewer iterations)
        $this->damage($zst, Inputs::get('mixed'), true, 6, 300);
    }

    public function testEveryTruncationOfASmallFrameIsRefused(): void
    {
        $frames = [
            (string) file_get_contents(__DIR__ . '/fixtures/tiny-level3-check.zst'),
            (string) file_get_contents(__DIR__ . '/fixtures/tiny-level19-nocheck.zst'),
            Zstd::compress(Inputs::get('tiny')),
        ];
        foreach ($frames as $f => $zst) {
            for ($n = 0; $n < strlen($zst); $n++) {
                try {
                    $out = Zstd::decompress(substr($zst, 0, $n));
                    $this->fail("frame $f cut to $n of " . strlen($zst) . ' bytes decoded (to ' . strlen($out) . ' bytes)');
                } catch (\UnexpectedValueException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    public function testEveryBitFlipOfASmallFrameWithChecksum(): void
    {
        $plain = Inputs::get('tiny');
        $zst = (string) file_get_contents(__DIR__ . '/fixtures/tiny-level3-check.zst');
        $this->assertSame(1, (ord($zst[4]) >> 2) & 1, 'the fixture has no checksum');
        $this->assertSame($plain, Zstd::decompress($zst));
        $threw = 0;
        for ($i = 0; $i < strlen($zst); $i++) {
            for ($bit = 0; $bit < 8; $bit++) {
                $c = $zst;
                $c[$i] = chr(ord($c[$i]) ^ (1 << $bit));
                try {
                    $this->assertTrue($plain === Zstd::decompress($c), "flipping bit $bit of byte $i returned wrong bytes");
                } catch (\UnexpectedValueException) {
                    $threw++;
                }
            }
        }
        $this->assertGreaterThan(strlen($zst) * 8 * 0.8, $threw);
    }

    public function testEveryBitFlipOfASmallFrameWithoutChecksum(): void
    {
        $zst = (string) file_get_contents(__DIR__ . '/fixtures/tiny-level19-nocheck.zst');
        for ($i = 0; $i < strlen($zst); $i++) {
            for ($bit = 0; $bit < 8; $bit++) {
                $c = $zst;
                $c[$i] = chr(ord($c[$i]) ^ (1 << $bit));
                try {
                    Zstd::decompress($c);
                } catch (\UnexpectedValueException) {
                }
                $this->addToAssertionCount(1);
            }
        }
    }

    private function damage(string $zst, string $plain, bool $checksum, int $seed, int $iterations = self::ITERATIONS): void
    {
        mt_srand($seed);
        $len = strlen($zst);
        $threw = 0;
        $same = 0;
        $other = 0;
        for ($i = 0; $i < $iterations; $i++) {
            $c = $zst;
            if ($i % 4 === 0) {
                $c = substr($c, 0, mt_rand(0, $len - 1));
            } else {
                for ($k = mt_rand(1, 3); $k > 0; $k--) {
                    $p = mt_rand(0, $len - 1);
                    $c[$p] = chr(ord($c[$p]) ^ (1 << mt_rand(0, 7)));
                }
            }
            try {
                $r = Zstd::decompress($c);
            } catch (\UnexpectedValueException) {
                $threw++;
                continue;
            }
            // (any other Throwable, including a converted warning, propagates and fails the test)
            if ($r === $plain) {
                $same++;
            } else {
                $other++;
                if ($checksum) {
                    $this->fail("iteration $i (seed $seed): a damaged frame with a checksum returned " . strlen($r) . ' wrong bytes');
                }
            }
        }
        $this->assertSame($iterations, $threw + $same + $other);
        // (without a checksum, a flip inside raw literals gives other bytes and no error, so only a share can be expected to throw)
        $this->assertGreaterThan($iterations / 4, $threw, 'damaged frames should mostly be refused');
    }
}

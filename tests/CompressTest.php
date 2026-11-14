<?php

declare(strict_types=1);

namespace Charm\Zstd\Tests;

use Charm\Zstd\Zstd;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** compress() followed by decompress(), over inputs at every boundary and of every character (the reference decoder is in CliTest). */
final class CompressTest extends TestCase
{
    /** @var array<string, string>|null */
    private static ?array $set = null;

    /** @return array<string, string> */
    public static function inputs(): array
    {
        return self::$set ??= Inputs::roundTripSet();
    }

    /** @return array<string, array{string}> */
    public static function names(): array
    {
        $cases = [];
        foreach (array_keys(self::inputs()) as $name) {
            $cases[$name] = [$name];
        }
        return $cases;
    }

    #[DataProvider('names')]
    public function testRoundTrip(string $name): void
    {
        $in = self::inputs()[$name];
        $zst = Zstd::compress($in);
        $this->assertSame("\x28\xB5\x2F\xFD", substr($zst, 0, 4));
        $this->assertTrue($in === Zstd::decompress($zst), "round trip of $name returned other bytes");
    }

    public function testEmptyInputIsAFrameOfNineBytes(): void
    {
        $this->assertSame(9, strlen(Zstd::compress('')));
        $this->assertSame('', Zstd::decompress(Zstd::compress('')));
    }

    public function testFrameDeclaresTheContentSize(): void
    {
        // Frame_Header_Descriptor: single segment, content size field of 1, 2 or 4 bytes
        $this->assertSame("\x20", Zstd::compress(str_repeat('a', 255))[4]);
        $this->assertSame("\x60", Zstd::compress(str_repeat('a', 256))[4]);
        $this->assertSame("\x60", Zstd::compress(str_repeat('a', 65791))[4]);
        $this->assertSame("\xA0", Zstd::compress(str_repeat('a', 65792))[4]);
    }

    public function testRepetitiveInputCompresses(): void
    {
        $this->assertLessThan(200, strlen(Zstd::compress(str_repeat('a', 100000))));
        $text = Inputs::text(200000, 77);
        $this->assertLessThan(strlen($text) * 0.8, strlen(Zstd::compress($text)));
    }

    public function testIncompressibleInputGrowsByAFewBytesOnly(): void
    {
        mt_srand(5);
        $r = '';
        for ($i = 0; $i < 200000; $i++) {
            $r .= chr(mt_rand(0, 255));
        }
        $this->assertLessThan(strlen($r) + 100, strlen(Zstd::compress($r)));
    }

    public function testConcatenatedFramesFromCompress(): void
    {
        $a = Inputs::text(5000, 1);
        $b = Inputs::text(70000, 2);
        $this->assertTrue($a . $b . $a === Zstd::decompress(Zstd::compress($a) . Zstd::compress($b) . Zstd::compress($a)));
    }
}

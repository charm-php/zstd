<?php

declare(strict_types=1);

namespace Charm\Zstd\Tests;

use Charm\Zstd\Zstd;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Cross-checks against the zstd command-line tool, in both directions. Skipped when `zstd` is not on PATH. */
final class CliTest extends TestCase
{
    protected function setUp(): void
    {
        if (!Cli::available()) {
            $this->markTestSkipped('The zstd command-line tool is not on PATH: cross-checks against the reference implementation skipped.');
        }
    }

    /** @return array<string, array{string}> */
    public static function names(): array
    {
        return CompressTest::names();
    }

    #[DataProvider('names')]
    public function testReferenceDecoderAcceptsOurOutput(string $name): void
    {
        $in = CompressTest::inputs()[$name];
        $out = Cli::decompress(Zstd::compress($in));
        $this->assertTrue($in === $out, "zstd -d returned other bytes for $name");
    }

    /** @return array<string, array{string}> */
    public static function settings(): array
    {
        $s = ['--fast=5', '-1', '-2', '-3', '-4', '-5', '-7', '-9', '-12', '-15', '-19', '--ultra -22', '-3 --long=27', '-3 --check', '-3 --no-check',
            '-1 --no-compress-literals', '-19 --no-content-size', '-3 -B4096 --no-content-size', '-1 -B4096', '--fast=1 --no-check',
            '-3 -T1 --no-check', '-8 --zstd=wlog=10', '-3 --zstd=strategy=1'];
        $cases = [];
        foreach ($s as $x) {
            $cases[$x] = [$x];
        }
        return $cases;
    }

    #[DataProvider('settings')]
    public function testOurDecoderOnTheReferenceCompressor(string $settings): void
    {
        $failures = [];
        foreach (CompressTest::inputs() as $name => $in) {
            if (strlen($in) > 600000 && preg_match('/-1[5-9]|-22/', $settings)) {
                continue; // (slow in the tool at the top levels: the boundary cases are small)
            }
            $zst = Cli::compress($in, $settings);
            try {
                if (Zstd::decompress($zst) !== $in) {
                    $failures[] = "$name: wrong output";
                }
            } catch (\Throwable $e) {
                $failures[] = "$name: " . get_class($e) . ': ' . $e->getMessage();
            }
        }
        $this->assertSame([], $failures, "zstd $settings");
    }

    public function testPipedInputHasNoContentSizeAndDecodes(): void
    {
        $in = CompressTest::inputs()['text all'];
        $zst = Cli::compressPiped($in, '-5');
        $this->assertSame(0, ord($zst[4]) & 0xE0, 'the frame declares a content size');
        $this->assertTrue($in === Zstd::decompress($zst));
    }

    public function testConcatenatedAndSkippableFrames(): void
    {
        $a = Inputs::get('text');
        $b = Inputs::get('small');
        $skip = "\x50\x2A\x4D\x18" . pack('V', 5) . 'hello';
        $zst = Cli::compressPiped($a, '-5') . $skip . Cli::compress($b, '-19') . $skip . Zstd::compress($a);
        $this->assertTrue($a . $b . $a === Zstd::decompress($zst));
        $this->assertTrue($a . $b . $a === Cli::decompress($zst), 'the reference tool disagrees about the concatenation');
    }

    public function testToolRefusesWhatWeRefuse(): void
    {
        $zst = Cli::compress(Inputs::get('small'), '-3 --check');
        $bad = substr($zst, 0, -2);
        $this->expectException(\RuntimeException::class);
        Cli::decompress($bad);
    }
}

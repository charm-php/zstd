<?php

declare(strict_types=1);

namespace Charm\Zstd\Tests;

use Charm\Zstd\{Decoder, Encoder, Zstd};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The incremental Encoder: its stream decodes with every decoder, and (without flush()) does not depend on how the input was cut. */
final class EncoderTest extends TestCase
{
    /** @param iterable<string> $chunks */
    private static function encode(iterable $chunks): string
    {
        $e = new Encoder();
        $out = '';
        foreach ($chunks as $c) {
            $out .= $e->encode($c);
        }
        return $out . $e->finish();
    }

    private static function decode(string $zst): string
    {
        $d = new Decoder();
        return $d->decode($zst) . $d->finish();
    }

    /** @return array<string, array{string}> */
    public static function names(): array
    {
        return CompressTest::names();
    }

    #[DataProvider('names')]
    public function testRoundTripThroughEveryDecoder(string $name): void
    {
        $in = CompressTest::inputs()[$name];
        $zst = self::encode(DecoderTest::split($in, 'random', 3));
        $this->assertTrue($in === Zstd::decompress($zst), "decompress() of the stream of $name");
        $this->assertTrue($in === self::decode($zst), "Decoder on the stream of $name");
        $this->assertTrue($in === self::decode(self::encode([$in])), "Decoder on the one-chunk stream of $name");
        if (Cli::available()) {
            $this->assertTrue($in === Cli::decompress($zst), "zstd -d on the stream of $name");
        }
    }

    #[DataProvider('names')]
    public function testChunkingNeverChangesTheOutput(string $name): void
    {
        $in = CompressTest::inputs()[$name];
        if (strlen($in) > 300000) {
            $in = substr($in, 0, 300000);
        }
        $want = self::encode([$in]);
        foreach (['bytes', '64k', 'random', 'tiny', 'empties'] as $pattern) {
            if ($pattern === 'bytes' && strlen($in) > 20000) {
                continue;
            }
            $this->assertTrue($want === self::encode(DecoderTest::split($in, $pattern, 8)), "$name cut as '$pattern'");
        }
    }

    public function testStreamIsDeterministicAndIndependentOfEarlierUse(): void
    {
        $in = Inputs::text(300000, 4);
        $a = self::encode([$in]);
        Zstd::compress(Inputs::text(200000, 5)); // (compress() keeps a hash table between calls)
        $this->assertSame($a, self::encode([$in]));
    }

    public function testFrameHeader(): void
    {
        $zst = self::encode(['hello']);
        // magic, descriptor: no single segment, content checksum, no content size, no dictionary; window descriptor: 128 KiB
        $this->assertSame("\x28\xB5\x2F\xFD\x04\x38", substr($zst, 0, 6));
        $this->assertSame('hello', Zstd::decompress($zst));
        // the checksum (the low 32 bits of XXH64) ends the frame
        $this->assertSame(substr(strrev(hash('xxh64', 'hello', true)), 0, 4), substr($zst, -4));
        // a damaged checksum is caught
        $bad = $zst;
        $bad[strlen($bad) - 1] = chr(ord($bad[strlen($bad) - 1]) ^ 1);
        $this->expectException(\UnexpectedValueException::class);
        self::decode($bad);
    }

    public function testBlocksAreAtMost128KiBAndEncodeReturnsEachBlockWhenItIsComplete(): void
    {
        $in = Inputs::text(400000, 6);
        $e = new Encoder();
        $this->assertSame('', $e->encode(''));
        $this->assertSame('', $e->encode(substr($in, 0, 131071)));
        $first = $e->encode(substr($in, 131071, 1));
        $this->assertStringStartsWith("\x28\xB5\x2F\xFD\x04\x38", $first, 'the header comes with the first block');
        $this->assertGreaterThan(6, strlen($first));
        $rest = $e->encode(substr($in, 131072)) . $e->finish();
        $zst = $first . $rest;
        $this->assertSame($in, Zstd::decompress($zst));
        $blocks = Frame::blocks($zst);
        $this->assertCount(4, $blocks); // 3 x 128 KiB + 6,784 bytes
        foreach ($blocks as $b) {
            $this->assertLessThanOrEqual(131072, $b['regenerated'] ?: 131072);
        }
    }

    public function testEmptyInput(): void
    {
        $e = new Encoder();
        $this->assertSame('', $e->flush());
        $zst = $e->finish();
        // header, an empty last raw block, the checksum of the empty string
        $this->assertSame("\x28\xB5\x2F\xFD\x04\x38\x01\x00\x00" . substr(strrev(hash('xxh64', '', true)), 0, 4), $zst);
        $this->assertSame('', Zstd::decompress($zst));
        $this->assertSame('', self::decode($zst));
        $this->assertSame($zst, self::encode(['', '']));
        if (Cli::available()) {
            $this->assertSame('', Cli::decompress($zst));
        }
    }

    public function testFinishAfterAWholeNumberOfBlocksWritesAnEmptyLastBlock(): void
    {
        $in = Inputs::text(262144, 7);
        $zst = self::encode([$in]);
        $this->assertSame($in, Zstd::decompress($zst));
        $this->assertSame("\x01\x00\x00", substr($zst, -7, 3));
    }

    public function testFinishResetsAndTheEncoderIsReusable(): void
    {
        $e = new Encoder();
        $a = Inputs::text(200000, 8);
        $b = Inputs::skewed(100000, 9, 20);
        $first = $e->encode($a) . $e->finish();
        $second = $e->encode($b) . $e->finish();
        $third = $e->encode($a) . $e->finish();
        $this->assertSame($first, $third, 'a reset encoder starts from nothing');
        $this->assertSame($a, Zstd::decompress($first));
        $this->assertSame($b, Zstd::decompress($second));
        // the streams concatenate into one that decodes to both
        $this->assertSame($a . $b, self::decode($first . $second));
    }

    public function testFlushAtRandomPoints(): void
    {
        $in = Inputs::text(600000, 12) . Inputs::skewed(100000, 13, 30);
        foreach ([1, 2, 3] as $seed) {
            mt_srand($seed);
            $e = new Encoder();
            $d = new Decoder();
            $zst = '';
            $got = '';
            for ($p = 0; $p < strlen($in);) {
                $n = mt_rand(0, 3) === 0 ? mt_rand(1, 200000) : mt_rand(1, 3000);
                $chunk = substr($in, $p, $n);
                $p += strlen($chunk);
                $out = $e->encode($chunk);
                if (mt_rand(0, 2) === 0) {
                    $out .= $e->flush();
                    $zst .= $out;
                    $got .= $d->decode($out);
                    $this->assertSame(substr($in, 0, $p), $got, "after a flush at $p (seed $seed) the decoder has all the input given");
                    $this->assertSame('', $e->flush(), 'nothing buffered');
                } else {
                    $zst .= $out;
                    $got .= $d->decode($out);
                }
            }
            $tail = $e->finish();
            $zst .= $tail;
            $got .= $d->decode($tail) . $d->finish();
            $this->assertTrue($in === $got);
            $this->assertTrue($in === Zstd::decompress($zst));
            if (Cli::available()) {
                $this->assertTrue($in === Cli::decompress($zst), "zstd -d on a stream with flushes (seed $seed)");
            }
        }
    }

    public function testFlushWithNothingBufferedReturnsNothing(): void
    {
        $e = new Encoder();
        $this->assertSame('', $e->flush());
        $zst = $e->encode('abc');
        $flushed = $e->flush();
        $this->assertNotSame('', $flushed);
        $zst .= $flushed;
        $this->assertSame('', $e->flush());
        $this->assertSame('', $e->flush());
        $zst .= $e->finish();
        $this->assertSame('abc', Zstd::decompress($zst));
    }

    public function testFlushOfEveryChunkStillDecodes(): void
    {
        $in = Inputs::text(50000, 14);
        $e = new Encoder();
        $zst = '';
        foreach (DecoderTest::split($in, 'tiny', 2) as $c) {
            $zst .= $e->encode($c) . $e->flush();
        }
        $zst .= $e->finish();
        $this->assertSame($in, Zstd::decompress($zst));
        $this->assertSame($in, self::decode($zst));
    }

    public function testMatchesReachBackAcrossBlocksWithinTheWindow(): void
    {
        // a block of text repeated at distances of 100,000 to 131,072 bytes: the encoder's matches reach the whole 128 KiB window
        // (decompress() rejects an offset beyond the window the header declares)
        $text = Inputs::text(131072, 15);
        foreach ([100000, 131000, 131072] as $period) {
            $in = '';
            while (strlen($in) < 700000) {
                $in .= substr($text, 0, $period);
            }
            $zst = self::encode(DecoderTest::split($in, '64k'));
            $this->assertLessThan(strlen($in) / 3, strlen($zst), "period $period compresses");
            $this->assertTrue($in === Zstd::decompress($zst));
            if (Cli::available()) {
                $this->assertTrue($in === Cli::decompress($zst));
            }
        }
    }

    public function testLongStreamTrimsItsBufferAndStillMatches(): void
    {
        // more than the 2 MiB after which the encoder cuts its buffer to the window: three times
        $pool = Inputs::text(300000, 16);
        $in = '';
        for ($k = 0; strlen($in) < 7000000; $k++) {
            $in .= substr($pool, ($k * 12345) % 100000, 150000);
        }
        $zst = self::encode(DecoderTest::split($in, '64k'));
        $this->assertTrue($in === self::decode($zst));
        $this->assertSame($zst, self::encode([$in]), 'the same bytes however the input is cut');
        $this->assertLessThan(strlen($in) / 2, strlen($zst));
    }

    public function testRatioIsCloseToCompress(): void
    {
        foreach (['text all', 'digits', 'mixed', 'low entropy'] as $name) {
            $in = CompressTest::inputs()[$name];
            $stream = strlen(self::encode([$in]));
            $one = strlen(Zstd::compress($in));
            // a 6-byte header (against 5 to 9), a 4-byte checksum, and a hash table that starts empty for every stream
            $this->assertLessThan($one * 1.02 + 16, $stream, $name);
        }
    }
}

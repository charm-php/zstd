<?php

declare(strict_types=1);

namespace Charm\Zstd\Tests;

use Charm\Zstd\Zstd;
use PHPUnit\Framework\TestCase;

/**
 * compress() keeps a hash table between calls on purpose (entries are only candidates, verified before use). Interleaving
 * different inputs in one process must therefore still give frames that every decoder reads back correctly.
 */
final class StateTest extends TestCase
{
    public function testInterleavedInputsAllDecode(): void
    {
        $pool = [];
        foreach (CompressTest::inputs() as $name => $in) {
            if (strlen($in) <= 140000) {
                $pool[$name] = $in;
            }
        }
        $names = array_keys($pool);
        mt_srand(99);
        $frames = [];
        for ($i = 0; $i < 400; $i++) {
            $name = $names[mt_rand(0, count($names) - 1)];
            $in = $pool[$name];
            $k = mt_rand(0, 3);
            if ($k === 1 && strlen($in) > 10) {
                $in = substr($in, mt_rand(0, strlen($in) >> 1)); // a different alignment of the same data
            } elseif ($k === 2) {
                $in = $in . $pool[$names[mt_rand(0, count($names) - 1)]];
            }
            $frames[] = [$in, Zstd::compress($in)];
        }
        // Check afterwards, in another order, so that nothing depends on the compress() call being the last one
        $order = array_keys($frames);
        mt_srand(100);
        shuffle($order);
        foreach ($order as $i) {
            [$in, $zst] = $frames[$i];
            $this->assertTrue($in === Zstd::decompress($zst), "own decoder, frame $i (" . strlen($in) . ' bytes)');
        }
        if (Cli::available()) {
            foreach ($order as $i) {
                [$in, $zst] = $frames[$i];
                $this->assertTrue($in === Cli::decompress($zst), "zstd -d, frame $i (" . strlen($in) . ' bytes)');
            }
        }
    }

    public function testSameInputTwiceBothDecode(): void
    {
        $in = Inputs::text(100000, 31);
        $x = Zstd::compress($in);
        Zstd::compress(Inputs::text(100000, 32));
        $y = Zstd::compress($in);
        $this->assertTrue($in === Zstd::decompress($x));
        $this->assertTrue($in === Zstd::decompress($y));
    }
}

<?php

declare(strict_types=1);

namespace Charm\Zstd\Tests;

/**
 * Deterministic test inputs. Everything is derived from mt_rand() with a fixed seed (mt_rand's sequence for a seed is fixed
 * across PHP versions), never from random_bytes(), so the fixture plaintexts can be regenerated instead of committed.
 * Each call reseeds the generator and leaves it reseeded: do not rely on mt_rand() state around these calls.
 */
final class Inputs
{
    public const NAMES = ['text', 'mixed', 'small', 'tiny', 'skew', 'lits'];

    public static function get(string $name): string
    {
        return match ($name) {
            'text' => self::text(160000, 1),
            'small' => self::text(40000, 2),
            'tiny' => self::text(700, 6),
            'mixed' => self::mixed(),
            'skew' => self::skewed(120000, 21, 24),
            'lits' => self::noRepeats(3000, 22, 40),
            default => throw new \InvalidArgumentException("Unknown input '$name'"),
        };
    }

    /** Word text from a vocabulary of 600 invented words, with punctuation and line breaks: a mix of short and long matches. */
    public static function text(int $size, int $seed): string
    {
        mt_srand($seed);
        $letters = 'etaoinshrdlcumwfgypbvkjxqz';
        $vocab = [];
        for ($i = 0; $i < 600; $i++) {
            $w = '';
            for ($k = mt_rand(2, 9); $k > 0; $k--) {
                // skewed towards the front of $letters
                $w .= $letters[(int) (mt_rand(0, 999) * mt_rand(0, 999) / 38400)];
            }
            $vocab[] = $w;
        }
        $out = '';
        $punct = [' ', ' ', ' ', ' ', ', ', '. ', "\n", ' - '];
        while (strlen($out) < $size) {
            $out .= $vocab[(int) (mt_rand(0, 599) * mt_rand(0, 599) / 600)] . $punct[mt_rand(0, 7)];
        }
        return substr($out, 0, $size);
    }

    /**
     * Bytes from an alphabet of $symbols values with a geometric frequency (a few symbols carry almost everything), in short repeating
     * phrases so that there are matches: the literals are skewed and the Huffman code is long-tailed.
     */
    public static function skewed(int $size, int $seed, int $symbols): string
    {
        mt_srand($seed);
        $pick = static function () use ($symbols): string {
            $k = 0;
            while ($k < $symbols - 1 && mt_rand(0, 99) < 60) {
                ++$k;
            }
            return chr(48 + $k * 3 % 200);
        };
        $out = '';
        while (strlen($out) < $size) {
            $out .= $pick();
            if (mt_rand(0, 9) === 0 && strlen($out) > 40) {
                $out .= substr($out, mt_rand(0, strlen($out) - 20), mt_rand(4, 12));
            }
        }
        return substr($out, 0, $size);
    }

    /**
     * $size bytes over $symbols values, skewed, in which no four bytes occur twice: the compressor finds no match, so the block is
     * all literals (zero sequences).
     */
    public static function noRepeats(int $size, int $seed, int $symbols): string
    {
        mt_srand($seed);
        $seen = [];
        $out = '';
        $tries = 0;
        while (strlen($out) < $size) {
            $k = mt_rand(0, mt_rand(0, $symbols - 1));
            $c = chr(40 + $k);
            $gram = strlen($out) >= 3 ? substr($out, -3) . $c : null;
            if ($gram !== null && isset($seen[$gram]) && ++$tries < 100000) {
                continue;
            }
            if ($gram !== null) {
                $seen[$gram] = true;
            }
            $out .= $c;
        }
        return $out;
    }

    /** Text, a run of zeros, a stretch of incompressible bytes, fixed-size binary records, and a repeated period-3 pattern. */
    private static function mixed(): string
    {
        $out = self::text(60000, 3) . str_repeat("\0", 20000);
        mt_srand(4);
        for ($i = 0; $i < 20000; $i++) {
            $out .= chr(mt_rand(0, 255));
        }
        for ($i = 0; $i < 3000; $i++) {
            $out .= pack('VvCCV', $i * 7, mt_rand(0, 3), 1, mt_rand(0, 255), 1000000 + $i);
        }
        return $out . str_repeat('xyz', 20000) . self::text(40000, 5);
    }

    /**
     * A list of named inputs around the block and header boundaries and of different character, for round trip tests.
     *
     * @return array<string, string>
     */
    public static function roundTripSet(): array
    {
        $text = self::text(500000, 10);
        mt_srand(11);
        $bin = '';
        for ($i = 0; $i < 450000; $i++) {
            // low-entropy structured binary: small deltas of a counter, with some noise
            $bin .= chr(($i >> 2) & 255) . chr(mt_rand(0, 9) === 0 ? mt_rand(0, 255) : ($i & 3));
        }
        $random = '';
        for ($i = 0; $i < 300000; $i++) {
            $random .= chr(mt_rand(0, 255));
        }
        $sizes = [0, 1, 2, 3, 5, 8, 15, 16, 17, 31, 32, 33, 63, 64, 100, 254, 255, 256, 257, 258, 1000, 4095, 4096, 4097,
            65535, 65536, 65537, 65790, 65791, 65792, 131071, 131072, 131073, 262144, 400000];
        $in = [];
        foreach ($sizes as $n) {
            $in["text[$n]"] = substr($text, 777, $n);
            $in["bin[$n]"] = substr($bin, 300, $n);
        }
        $in['random[300000]'] = $random;
        $in['random[1000]'] = substr($random, 0, 1000);
        $in['zeros[1 MiB]'] = str_repeat("\0", 1 << 20);
        $in['zeros[131072]'] = str_repeat("\0", 131072);
        $in['zeros[300000]'] = str_repeat("\0", 300000);
        $in['byte x 17'] = str_repeat('q', 17);
        $in['ab x 200000'] = str_repeat('ab', 200000);
        $in['period 7'] = str_repeat('abcdefg', 50000);
        $in['period 3'] = str_repeat('xyz', 90001);
        $in['period 70000 (long)'] = str_repeat(substr($random, 0, 70000), 6);
        $in['period 40000 (>32K)'] = str_repeat(substr($text, 0, 40000), 5);
        mt_srand(12);
        $low = '';
        for ($i = 0; $i < 300000; $i++) {
            $low .= 'aaab'[mt_rand(0, 3)];
        }
        $in['low entropy'] = $low;
        $digits = '';
        for ($i = 0; $i < 60000; $i++) {
            $digits .= mt_rand(0, 999999) . ',';
        }
        $in['digits'] = $digits;
        $in['text all'] = $text;
        $in['mixed'] = self::mixed();
        $in['text + zeros + text'] = substr($text, 0, 100000) . str_repeat("\0", 140000) . substr($text, 0, 100000);
        return $in;
    }
}

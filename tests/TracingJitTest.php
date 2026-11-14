<?php

declare(strict_types=1);

namespace Charm\Zstd\Tests;

use Charm\Zstd\Zstd;
use PHPUnit\Framework\TestCase;

/**
 * PHP 8.4 and 8.5's tracing JIT miscompiled the decoder's bitstream refill loop (decompress() threw "Corrupted Zstandard sequence"
 * on valid frames). The decoder avoids that code shape now. This test decodes in a child PHP process with the tracing JIT on, so it
 * covers the JIT whatever the settings of the process running PHPUnit. Skipped when that PHP cannot enable the JIT.
 */
final class TracingJitTest extends TestCase
{
    public function testDecodingUnderTheTracingJit(): void
    {
        $script = <<<'PHP'
            <?php
            require $argv[1];
            if (!(\function_exists('opcache_get_status') && (\opcache_get_status(false)['jit']['on'] ?? false))) {
                echo "no-jit\n";
                exit(0);
            }
            // generated text (fixed seed), compressed by this library: enough sequences for the JIT to compile the decoder's loops
            \mt_srand(7);
            $words = [];
            for ($i = 0; $i < 3000; $i++) {
                $w = '';
                for ($j = \mt_rand(2, 9); $j--;) {
                    $w .= \chr(\mt_rand(97, 122));
                }
                $words[] = $w;
            }
            $text = '';
            while (\strlen($text) < 300000) {
                $text .= $words[\mt_rand(0, 2999)] . ' ';
            }
            $zst = \Charm\Zstd\Zstd::compress($text);
            for ($i = 0; $i < 3; $i++) {
                if (\Charm\Zstd\Zstd::decompress($zst) !== $text) {
                    echo "wrong output\n";
                    exit(1);
                }
            }
            // the streaming Decoder in small chunks, and the Encoder, under the JIT too
            for ($i = 0; $i < 3; $i++) {
                $d = new \Charm\Zstd\Decoder();
                $got = '';
                foreach (\str_split($zst, 3001) as $c) {
                    $got .= $d->decode($c);
                }
                $got .= $d->finish();
                $e = new \Charm\Zstd\Encoder();
                $stream = '';
                foreach (\str_split($text, 50000) as $c) {
                    $stream .= $e->encode($c);
                }
                $stream .= $e->finish();
                if ($got !== $text || \Charm\Zstd\Zstd::decompress($stream) !== $text) {
                    echo "wrong streaming output\n";
                    exit(1);
                }
            }
            echo "ok\n";
            PHP;
        $file = tempnam(sys_get_temp_dir(), 'zstdjit');
        file_put_contents($file, $script);
        try {
            $cmd = escapeshellarg(PHP_BINARY) . ' -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=64M'
                . ' -d opcache.file_update_protection=0 ' . escapeshellarg($file) . ' '
                . escapeshellarg(\dirname(__DIR__) . '/vendor/autoload.php') . ' 2>&1';
            $out = trim((string) shell_exec($cmd));
        } finally {
            @unlink($file);
        }
        if ($out === 'no-jit') {
            $this->markTestSkipped('This PHP cannot enable the tracing JIT (opcache not available in the CLI).');
        }
        $this->assertSame('ok', $out, 'decoding in a child process with opcache.jit=tracing');
    }

    /**
     * PHP 8.4 and 8.5's tracing JIT compiles a trace of the sequence loop of the block decoder only after some 60 iterations, and
     * miscompiled it: wrong FSE states from then on ("Corrupted Zstandard sequence"). Every small test passed. This runs the decoders in a child
     * process with the tracing JIT on, over input that is big enough and real enough (machine code) for that: the 128 KiB block of a
     * `zstd -1` frame of the PHP binary (tests/fixtures/jit-block.zst, 56 KB), by decompress() and by the Decoder in 1 KiB chunks.
     * It fails on the decoder as it was in 0.1.0-RC1 (PHP 8.4, 8.5).
     */
    public function testCommittedBlockOfMachineCodeUnderTheTracingJit(): void
    {
        $zst = __DIR__ . '/fixtures/jit-block.zst';
        $sha = '4c6b13e4832f39b571a9da0d9dea3c8d9b394359a08097e92be5f3ebdd59f350';
        $this->childDecodes([$zst], $sha);
    }

    /**
     * The same on a multi-MB input made by the real tool at -1, -3, -19, --long=27 -3 and --fast=5 (and by compress() and the Encoder):
     * the first 3 MB of the PHP binary (machine code on whatever this runs on) and 1.5 MB of generated text and 1 MB of skewed bytes.
     * Skipped without the zstd tool.
     */
    public function testMultiMegabyteFramesFromTheReferenceToolUnderTheTracingJit(): void
    {
        if (!Cli::available()) {
            $this->markTestSkipped('The zstd command-line tool is not on PATH.');
        }
        $plain = (string) file_get_contents(PHP_BINARY, false, null, 0, 3 << 20) . Inputs::text(1500000, 21) . Inputs::skewed(1000000, 22, 40);
        $dir = sys_get_temp_dir() . '/charm-zstd-jit-' . getmypid();
        mkdir($dir);
        try {
            $names = [];
            foreach (['-1', '-3', '-19', '--long=27 -3', '--fast=5'] as $i => $args) {
                file_put_contents("$dir/f$i.zst", Cli::compress($plain, $args));
                $names[] = "$dir/f$i.zst";
            }
            file_put_contents("$dir/ours.zst", Zstd::compress($plain));
            $names[] = "$dir/ours.zst";
            $e = new \Charm\Zstd\Encoder();
            $stream = '';
            foreach (str_split($plain, 65536) as $c) {
                $stream .= $e->encode($c);
            }
            file_put_contents("$dir/stream.zst", $stream . $e->finish());
            $names[] = "$dir/stream.zst";
            $this->childDecodes($names, hash('sha256', $plain));
        } finally {
            array_map('unlink', glob("$dir/*") ?: []);
            rmdir($dir);
        }
    }

    /**
     * The compressor under the 8.4/8.5 tracing JIT: assigning to the array a `foreach` iterates over (which the Huffman code length limiting did)
     * was miscompiled, and the clamp to 11 bits was lost, so frames with Huffman-coded literals of a very skewed distribution were invalid.
     * Hundreds of such inputs (a few frequent bytes and many rare ones: a code deeper than 11 bits) in one child process, compressed and decoded again.
     */
    public function testHuffmanCodeLengthLimitingUnderTheTracingJit(): void
    {
        $script = <<<'PHP'
            <?php
            require $argv[1];
            if (!(\function_exists('opcache_get_status') && (\opcache_get_status(false)['jit']['on'] ?? false))) {
                echo "no-jit\n";
                exit(0);
            }
            \mt_srand(7);
            for ($i = 0; $i < 600; $i++) {
                // 40 to 256 distinct bytes, most of them rare (1 to 5 times) and the rest frequent (up to 1000 times): an optimal code deeper than 11 bits
                $in = '';
                for ($c = \mt_rand(40, 256); $c > 0; $c--) {
                    $in .= \str_repeat(\chr(\mt_rand(0, 255)), \mt_rand(0, 1) ? \mt_rand(1, 1000) : \mt_rand(1, 5));
                }
                $in = \str_shuffle($in);
                try {
                    $out = \Charm\Zstd\Zstd::decompress(\Charm\Zstd\Zstd::compress($in));
                } catch (\Throwable $e) {
                    echo "input $i: ", $e->getMessage(), "\n";
                    exit(1);
                }
                if ($out !== $in) {
                    echo "input $i: wrong output\n";
                    exit(1);
                }
            }
            echo "ok\n";
            PHP;
        $file = tempnam(sys_get_temp_dir(), 'zstdjit');
        file_put_contents($file, $script);
        try {
            $out = trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=128M'
                . ' -d opcache.file_update_protection=0 ' . escapeshellarg($file) . ' ' . escapeshellarg(\dirname(__DIR__) . '/vendor/autoload.php') . ' 2>&1'));
        } finally {
            @unlink($file);
        }
        if ($out === 'no-jit') {
            $this->markTestSkipped('This PHP cannot enable the tracing JIT (opcache not available in the CLI).');
        }
        $this->assertSame('ok', $out, 'compressing skewed inputs in a child process with opcache.jit=tracing');
    }

    /** @param list<string> $files frames that must all decode to content with this SHA-256, by decompress() and by the Decoder */
    private function childDecodes(array $files, string $sha): void
    {
        $script = <<<'PHP'
            <?php
            require $argv[1];
            if (!(\function_exists('opcache_get_status') && (\opcache_get_status(false)['jit']['on'] ?? false))) {
                echo "no-jit\n";
                exit(0);
            }
            foreach (\array_slice($argv, 3) as $f) {
                $zst = \file_get_contents($f);
                try {
                    $a = \hash('sha256', \Charm\Zstd\Zstd::decompress($zst));
                    $d = new \Charm\Zstd\Decoder();
                    $h = \hash_init('sha256');
                    for ($p = 0; $p < \strlen($zst); $p += 1024) {
                        \hash_update($h, $d->decode(\substr($zst, $p, 1024)));
                    }
                    \hash_update($h, $d->finish());
                    $b = \hash_final($h);
                } catch (\Throwable $e) {
                    echo \basename($f), ': ', $e->getMessage(), "\n";
                    exit(1);
                }
                if ($a !== $argv[2] || $b !== $argv[2]) {
                    echo \basename($f), ": wrong output\n";
                    exit(1);
                }
            }
            echo "ok\n";
            PHP;
        $file = tempnam(sys_get_temp_dir(), 'zstdjit');
        file_put_contents($file, $script);
        try {
            $cmd = escapeshellarg(PHP_BINARY) . ' -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=128M'
                . ' -d opcache.file_update_protection=0 ' . escapeshellarg($file) . ' ' . escapeshellarg(\dirname(__DIR__) . '/vendor/autoload.php')
                . ' ' . $sha . ' ' . implode(' ', array_map('escapeshellarg', $files)) . ' 2>&1';
            $out = trim((string) shell_exec($cmd));
        } finally {
            @unlink($file);
        }
        if ($out === 'no-jit') {
            $this->markTestSkipped('This PHP cannot enable the tracing JIT (opcache not available in the CLI).');
        }
        $this->assertSame('ok', $out, 'decoding in a child process with opcache.jit=tracing');
    }
}

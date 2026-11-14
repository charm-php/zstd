<?php

declare(strict_types=1);

namespace Charm\Zstd\Tests;

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
}

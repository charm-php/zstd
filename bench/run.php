<?php

declare(strict_types=1);

/**
 * One command: php bench/run.php [--compress] [--runs=7]   (--compress runs compress.php, the compressor's sizes and speed, instead of bench.php)
 * Runs bench.php twice, with the tracing JIT and without it (opcache on in both, so only the JIT differs).
 */

$compress = in_array('--compress', $argv, true);
$args = implode(' ', array_map('escapeshellarg', array_filter(array_slice($argv, 1), static fn (string $a): bool => $a !== '--compress')));
$php = escapeshellarg(PHP_BINARY);
$common = '-d opcache.enable_cli=1 -d opcache.file_update_protection=0';
$script = escapeshellarg(__DIR__ . ($compress ? '/compress.php' : '/bench.php'));

echo "# With the tracing JIT\n\n";
passthru("$php $common -d opcache.jit_buffer_size=128M -d opcache.jit=1255 $script $args", $a);
echo "\n# Without the JIT\n\n";
passthru("$php $common -d opcache.jit_buffer_size=0 $script $args", $b);
exit($a ?: $b);

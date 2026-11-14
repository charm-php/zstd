<?php

declare(strict_types=1);

/**
 * Benchmarks the compressor: output size against `zstd -1` and `zstd -3` (when the tool is on PATH), compress speed, and the speed of
 * decoding the package's own output. Run through run.php --compress (with and without the JIT).
 *
 *     php bench/compress.php [--runs=5] [--filter=text]
 *
 * Inputs (fixed seeds, built here): text (source-code-like), words (Inputs::text), digits (decimal numbers, one per line), json (records
 * with repeated keys), binary (Inputs 'mixed'), int64 (PLAIN int64 of a slowly rising counter, like a Parquet page), dict-idx (RLE/bit-packed-like
 * bytes of small dictionary indices), random (incompressible). MB/s is megabytes (2^20) of uncompressed data per second, best of --runs.
 */

use Charm\Zstd\Tests\Cli;
use Charm\Zstd\Zstd;

require __DIR__ . '/../vendor/autoload.php';

$opts = getopt('', ['runs::', 'filter::']);
$runs = (int) ($opts['runs'] ?? 5);
$filter = (string) ($opts['filter'] ?? '');

function best(callable $fn, int $runs): float
{
    $fn();
    $best = INF;
    for ($i = 0; $i < $runs; $i++) {
        gc_collect_cycles();
        $s = hrtime(true);
        $x = $fn();
        $best = min($best, (hrtime(true) - $s) / 1e9);
        unset($x);
    }
    return $best;
}

require __DIR__ . '/compress-inputs.php';

$zstd = Cli::available();
printf("%-9s %9s %9s %9s %9s %8s %9s %9s %10s\n", 'input', 'bytes', 'ours', 'zstd -1', 'zstd -3', 'ratio', 'comp MB/s', 'dec MB/s', 'dec vs src');
foreach (inputs() as $name => $data) {
    if ($filter !== '' && !str_contains($name, $filter)) {
        continue;
    }
    $n = strlen($data);
    $c = Zstd::compress($data);
    if (Zstd::decompress($c) !== $data) {
        fwrite(STDERR, "ROUND TRIP FAILED for $name\n");
        exit(1);
    }
    if ($zstd && Cli::decompress($c) !== $data) {
        fwrite(STDERR, "zstd -d REJECTS the output for $name\n");
        exit(1);
    }
    $t = best(fn () => Zstd::compress($data), $runs);
    $d = best(fn () => Zstd::decompress($c), $runs);
    $z1 = $zstd ? strlen(Cli::compress($data, '-1')) : 0;
    $z3 = $zstd ? strlen(Cli::compress($data, '-3')) : 0;
    printf("%-9s %9d %9d %9d %9d %8.3f %9.1f %9.1f %10s\n", $name, $n, strlen($c), $z1, $z3, strlen($c) / $n, $n / 1048576 / $t, $n / 1048576 / $d, '');
}

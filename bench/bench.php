<?php

declare(strict_types=1);

/**
 * Benchmarks charm/zstd: decoding at zstd levels 1, 3 and 19 (when the zstd tool is on PATH), the package's own compressor,
 * the streaming Decoder (64 KiB chunks) and Encoder (64 KiB chunks), and native gzdecode() on the same data as a reference. Run it through run.php, which starts this script with and without the JIT:
 *
 *     php bench/run.php
 *
 * Options: --runs=7
 * Times are the best of --runs after two warm-up runs. MB/s is megabytes (2^20 bytes) of uncompressed data per second.
 * The inputs are built here from fixed seeds (nothing is downloaded) except the PHP binary, which is whatever php this is run with; they are written to bench/data/ (git-ignored) for the zstd tool.
 */

use Charm\Zstd\{Decoder, Encoder, Zstd};

require __DIR__ . '/../vendor/autoload.php';

$opts = getopt('', ['runs::']);
$runs = (int) ($opts['runs'] ?? 7);
$dir = __DIR__ . '/data';
@mkdir($dir);

/** @return float best time in seconds */
function best(callable $fn, int $runs): float
{
    for ($i = 0; $i < 2; $i++) {
        $fn();
    }
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

/**
 * Source-code-like text, generated with a fixed seed (mt_rand's sequence for a seed is the same on every PHP version): PHP-ish
 * lines built from a vocabulary of identifiers, keywords and operators, with indentation, comments and repeated statements
 * (1.3 MB). It does not depend on any file on the machine.
 */
function sourceText(): string
{
    mt_srand(4);
    $letters = 'etaoinshrdlcumfpgwybvk';
    $ident = [];
    for ($i = 0; $i < 400; $i++) {
        $w = '';
        for ($k = mt_rand(2, 4); $k > 0; $k--) {
            $part = '';
            for ($j = mt_rand(2, 5); $j > 0; $j--) {
                $part .= $letters[(int) (mt_rand(0, 999) * mt_rand(0, 999) / 45400)];
            }
            $w .= $k === 1 || $w === '' ? $part : ucfirst($part);
        }
        $ident[] = $w;
    }
    $kw = ['if', 'foreach', 'return', 'while', 'throw new', 'self::', '$this->', 'public function', 'private static', 'array', 'string', 'int'];
    $stmts = [];
    for ($i = 0; $i < 1500; $i++) {
        $a = $ident[mt_rand(0, 399)];
        $b = $ident[mt_rand(0, 399)];
        $stmts[] = match (mt_rand(0, 5)) {
            0 => "\$$a = \$$b + " . mt_rand(0, 255) . ';',
            1 => $kw[mt_rand(0, 11)] . " (\$$a !== \$$b) {",
            2 => "return \$this->$a(\$$b, '$a');",
            3 => "// $a: " . $ident[mt_rand(0, 399)] . ' ' . $ident[mt_rand(0, 399)],
            4 => "}",
            default => '$' . $a . '[] = ' . $kw[mt_rand(0, 11)] . ' ' . $b . '($' . $a . ');',
        };
    }
    $out = '';
    $depth = 1;
    while (strlen($out) < 1300000) {
        // a mix of fresh statements (skewed towards the front of the list) and repeats of the previous lines
        $s = $stmts[(int) (mt_rand(0, 1499) * mt_rand(0, 1499) / 1500)];
        $depth = max(0, min(6, $depth + mt_rand(-1, 1)));
        $out .= str_repeat('    ', $depth) . $s . "\n";
    }
    return substr($out, 0, 1300000);
}

/** Synthetic word text: 3000 invented words in random order (4.5 MB). */
function words(): string
{
    mt_srand(5);
    $letters = 'abcdefghijklmnopqrstuvwxyz';
    $vocab = [];
    for ($i = 0; $i < 3000; $i++) {
        $w = '';
        for ($k = mt_rand(2, 9); $k > 0; $k--) {
            $w .= $letters[mt_rand(0, 25)];
        }
        $vocab[] = $w;
    }
    $out = [];
    for ($i = 0; $i < 700000; $i++) {
        $out[] = $vocab[mt_rand(0, 2999)];
    }
    return implode(' ', $out) . ' ';
}

/** Literal-heavy: random decimal numbers separated by commas (few repeated strings, so most output comes from Huffman literals). */
function digits(): string
{
    mt_srand(6);
    $out = [];
    for ($i = 0; $i < 400000; $i++) {
        $out[] = mt_rand(0, 9999999);
    }
    return implode(',', $out);
}

/** Literal-heavy: bytes with a skewed distribution (each byte the product of two uniform draws), no repeats worth matching. */
function skewed(): string
{
    mt_srand(8);
    $out = '';
    for ($i = 0; $i < 2000000; $i++) {
        $out .= chr((int) (mt_rand(0, 255) * mt_rand(0, 255) / 255));
    }
    return $out;
}

/** The streaming decoder over 64 KiB chunks: the bytes it returned (the output is counted, not collected, in the timed runs). */
function streamDecode(string $z, bool $collect = false): string|int
{
    $d = new Decoder();
    $n = 0;
    $out = '';
    for ($p = 0, $len = strlen($z); $p < $len; $p += 65536) {
        $r = $d->decode(substr($z, $p, 65536));
        $collect ? $out .= $r : $n += strlen($r);
    }
    $r = $d->finish();
    return $collect ? $out . $r : $n + strlen($r);
}

function streamEncode(string $in): string
{
    $e = new Encoder();
    $out = [];
    for ($p = 0, $len = strlen($in); $p < $len; $p += 65536) {
        $out[] = $e->encode(substr($in, $p, 65536));
    }
    $out[] = $e->finish();
    return implode('', $out);
}

$inputs = [
    'source text' => sourceText(),
    'PHP binary (first 6 MiB)' => (string) file_get_contents(PHP_BINARY, false, null, 0, 6 << 20),
    'synthetic words' => words(),
    'literal-heavy: digits' => digits(),
    'literal-heavy: skewed bytes' => skewed(),
];

$cli = false;
@exec('zstd --version 2>/dev/null', $o, $code);
$cli = $code === 0;

printf("PHP %s, JIT %s, zstd tool: %s\n", PHP_VERSION, ini_get('opcache.jit_buffer_size') === '0' || ini_get('opcache.jit_buffer_size') === '' ? 'off' : 'on (' . ini_get('opcache.jit') . ')', $cli ? trim((string) shell_exec('zstd --version 2>&1')) : 'not found (zstd levels skipped)');
echo "Decoding is checked against the original on every measured frame (the streaming paths too).\n\n";

printf("%-42s | %-34s | %9s | %7s\n", 'input', 'operation', 'MB/s', 'ratio');
printf("%'-42s-+-%'-34s-+-%'-9s-+-%'-7s\n", '', '', '', '');
$row = static function (string $name, string $op, float $mb, string $ratio) {
    printf("%-42s | %-34s | %9.1f | %7s\n", $name, $op, $mb, $ratio);
};

foreach ($inputs as $name => $in) {
    $n = strlen($in);
    $mb = $n / 1048576;
    $label = sprintf('%s (%.1f MB)', $name, $mb);
    if ($cli) {
        $file = "$dir/" . md5($name) . '.in';
        file_put_contents($file, $in);
        foreach (['-1', '-3', '-19'] as $lv) {
            $z = (string) shell_exec("zstd -q $lv -c " . escapeshellarg($file));
            if (Zstd::decompress($z) !== $in) {
                fwrite(STDERR, "decode mismatch ($name, zstd $lv)\n");
                exit(1);
            }
            $row($label, "decode `zstd $lv` output", $mb / best(fn () => Zstd::decompress($z), $runs), sprintf('%.2f', $n / strlen($z)));
            if ($lv === '-3') {
                $row($label, 'Decoder, 64 KiB chunks, `zstd -3`', $mb / best(fn () => streamDecode($z), $runs), sprintf('%.2f', $n / strlen($z)));
            }
        }
        @unlink($file);
    }
    $gz = gzencode($in, 6);
    $row($label, 'gzdecode() (native), level 6', $mb / best(fn () => gzdecode($gz), $runs), sprintf('%.2f', $n / strlen($gz)));
    $z = Zstd::compress($in);
    if (Zstd::decompress($z) !== $in) {
        fwrite(STDERR, "round trip mismatch ($name)\n");
        exit(1);
    }
    $row($label, 'Zstd::compress()', $mb / best(fn () => Zstd::compress($in), min($runs, 3)), sprintf('%.2f', $n / strlen($z)));
    $row($label, 'decode Zstd::compress() output', $mb / best(fn () => Zstd::decompress($z), $runs), sprintf('%.2f', $n / strlen($z)));
    if (streamDecode($z, true) !== $in) {
        fwrite(STDERR, "streaming decode mismatch ($name)\n");
        exit(1);
    }
    $row($label, 'Decoder, 64 KiB chunks, same frame', $mb / best(fn () => streamDecode($z), $runs), sprintf('%.2f', $n / strlen($z)));
    $zs = streamEncode($in);
    if (Zstd::decompress($zs) !== $in || streamDecode($zs, true) !== $in) {
        fwrite(STDERR, "stream round trip mismatch ($name)\n");
        exit(1);
    }
    $row($label, 'Encoder, 64 KiB chunks', $mb / best(fn () => streamEncode($in), min($runs, 3)), sprintf('%.2f', $n / strlen($zs)));
    $row($label, 'decompress() of the Encoder stream', $mb / best(fn () => Zstd::decompress($zs), $runs), sprintf('%.2f', $n / strlen($zs)));
    $row($label, 'Decoder, 64 KiB chunks, Encoder stream', $mb / best(fn () => streamDecode($zs), $runs), sprintf('%.2f', $n / strlen($zs)));
    echo "\n";
}

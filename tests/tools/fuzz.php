<?php

declare(strict_types=1);

/**
 * Fuzzes the compressor against the reference decoder: random inputs of random sizes, alphabets and structure go through
 * Zstd::compress(); every result must decode to the input with this package's decoder and with `zstd -d`.
 *
 *     php tests/tools/fuzz.php [--cases=20000] [--seed=1] [--start=0]
 *
 * Case $i is generated from mt_srand(seed * 1000003 + i), so a failing case is reproduced with --start=$i --cases=1.
 * Inputs mix literal stretches over a skewed alphabet of 1 to 256 symbols, copies from anywhere earlier (offsets up to 300 KB), runs,
 * and incompressible bytes; sizes are mostly small (0 to 3,000) with some up to 300,000 bytes (several blocks).
 * Prints a summary and the paths exercised; exit status 1 on any failure.
 */

use Charm\Zstd\Tests\{Cli, Frame};
use Charm\Zstd\Zstd;

require __DIR__ . '/../../vendor/autoload.php';

$opts = getopt('', ['cases::', 'seed::', 'start::']);
$cases = (int) ($opts['cases'] ?? 20000);
$seed = (int) ($opts['seed'] ?? 1);
$start = (int) ($opts['start'] ?? 0);
if (!Cli::available()) {
    fwrite(STDERR, "The zstd command-line tool is needed.\n");
    exit(2);
}

function generate(int $i, int $seed): string
{
    mt_srand($seed * 1000003 + $i);
    $r = mt_rand(0, 99);
    $size = $r < 70 ? mt_rand(0, 3000) : ($r < 95 ? mt_rand(3000, 70000) : mt_rand(70000, 300000));
    $k = [1, 2, 3, 4, 8, 16, 40, 100, 256][mt_rand(0, 8)];
    $skew = mt_rand(0, 3);
    $sym = static function () use ($k, $skew): string {
        if ($skew === 0) {
            return chr(mt_rand(0, $k - 1));
        }
        $x = mt_rand(0, $k - 1);
        for ($j = 0; $j < $skew; $j++) {
            $x = mt_rand(0, $x);
        }
        return chr($x);
    };
    $copyBias = mt_rand(0, 9);
    $out = '';
    while (strlen($out) < $size) {
        $op = mt_rand(0, 9);
        if ($op < 5 || strlen($out) < 8) {
            for ($n = mt_rand(1, 60); $n > 0; $n--) {
                $out .= $sym();
            }
        } elseif ($op < 5 + ($copyBias > 4 ? 4 : 2)) {
            $len = mt_rand(0, 4) === 0 ? mt_rand(100, 400) : mt_rand(4, 40);
            $from = mt_rand(0, 3) === 0 ? mt_rand(0, strlen($out) - 1) : max(0, strlen($out) - mt_rand(1, min(strlen($out), 300)));
            $out .= substr($out, $from, $len);
        } elseif ($op < 9) {
            $out .= str_repeat($sym(), mt_rand(2, 600));
        } else {
            for ($n = mt_rand(1, 100); $n > 0; $n--) {
                $out .= chr(mt_rand(0, 255));
            }
        }
    }
    return substr($out, 0, $size);
}

$tmp = sys_get_temp_dir() . '/charm-zstd-fuzz-' . getmypid() . '.zst';
$fail = 0;
$bytesIn = 0;
$bytesOut = 0;
$paths = ['raw block' => 0, 'RLE block' => 0, 'literals only' => 0, 'raw literals' => 0, 'RLE literals' => 0, 'Huffman literals, 1 stream' => 0, 'Huffman literals, 4 streams' => 0,
    'predefined tables' => 0, 'RLE tables' => 0, 'FSE tables' => 0];
$t0 = microtime(true);
for ($i = $start; $i < $start + $cases; $i++) {
    $in = generate($i, $seed);
    $zst = Zstd::compress($in);
    $bytesIn += strlen($in);
    $bytesOut += strlen($zst);
    $problem = null;
    try {
        if (Zstd::decompress($zst) !== $in) {
            $problem = 'this decoder returned other bytes';
        }
    } catch (Throwable $e) {
        $problem = 'this decoder: ' . $e->getMessage();
    }
    file_put_contents($tmp, $zst);
    $ref = shell_exec('zstd -q -d -c ' . escapeshellarg($tmp) . ' 2>/dev/null');
    if ($ref !== $in && !($in === '' && ($ref === null || $ref === ''))) {
        $problem = ($problem ? $problem . '; ' : '') . 'zstd -d returned ' . ($ref === null ? 'nothing' : strlen((string) $ref) . ' other bytes');
    }
    if ($problem !== null) {
        ++$fail;
        printf("FAIL case %d (seed %d, %d bytes): %s\n", $i, $seed, strlen($in), $problem);
        if ($fail >= 20) {
            break;
        }
        continue;
    }
    if (strlen($in) >= 16) {
        $b = Frame::firstBlock($zst);
        if ($b['blockType'] === 0) {
            ++$paths['raw block'];
        } elseif ($b['blockType'] === 1) {
            ++$paths['RLE block'];
        } else {
            ++$paths[['raw literals', 'RLE literals', $b['literalsFormat'] === 0 ? 'Huffman literals, 1 stream' : 'Huffman literals, 4 streams'][min($b['literalsType'], 2)]];
            if ($b['sequences'] === 0) {
                ++$paths['literals only'];
            } else {
                foreach (array_unique($b['modes']) as $m) {
                    ++$paths[['predefined tables', 'RLE tables', 'FSE tables'][$m]];
                }
            }
        }
    }
}
@unlink($tmp);
printf("fuzz: %d cases (seed %d, from %d), %d failures; %.1f MB in, %.1f MB out; %.0f s\n", $i - $start, $seed, $start, $fail, $bytesIn / 1e6, $bytesOut / 1e6, microtime(true) - $t0);
echo 'first block of each case with 16+ bytes: ', implode(', ', array_map(static fn ($k, $v) => "$k $v", array_keys($paths), $paths)), "\n";
exit($fail > 0 ? 1 : 0);

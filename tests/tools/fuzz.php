<?php

declare(strict_types=1);

/**
 * Fuzzes the codec against the reference decoder: random inputs of random sizes, alphabets and structure go through
 * Zstd::compress(); every result must decode to the input with this package's decoder and with `zstd -d`. The streaming modes add:
 *
 *   encoder: the input goes through an Encoder in random chunks with flush() at random points (a Decoder given the output so far must
 *            have all the input so far); the stream must decode with decompress(), with a Decoder over random chunks and with `zstd -d`;
 *            and the same input without flush() cut differently must give the same bytes;
 *   decoder: compress() output, and (every 8th case, when the tool can compress) the tool's own frames at a random level, go through a
 *            Decoder in random chunks: the same bytes as decompress();
 *   damage:  the frames above with a truncation or a few changed bytes: a Decoder in random chunks throws exactly when decompress() does
 *            and returns the same bytes otherwise.
 *
 *     php tests/tools/fuzz.php [--cases=20000] [--seed=1] [--start=0] [--modes=oneshot,encoder,decoder,damage] [--big=100]
 *
 * --big=N makes one case in N a large one (50 KB to 3 MB cut from this PHP binary: machine code), which small random inputs never were.
 *
 * Case $i is generated from mt_srand(seed * 1000003 + i), so a failing case is reproduced with --start=$i --cases=1.
 * Inputs mix literal stretches over a skewed alphabet of 1 to 256 symbols, copies from anywhere earlier (offsets up to 300 KB), runs,
 * and incompressible bytes; sizes are mostly small (0 to 3,000) with some up to 300,000 bytes (several blocks).
 * Prints a summary and the paths exercised; exit status 1 on any failure.
 */

use Charm\Zstd\Tests\{Cli, DecoderTest, Frame};
use Charm\Zstd\{Decoder, Encoder, Zstd};

require __DIR__ . '/../../vendor/autoload.php';

$opts = getopt('', ['cases::', 'seed::', 'start::', 'modes::', 'big::']);
$big = (int) ($opts['big'] ?? 0);
$modes = explode(',', (string) ($opts['modes'] ?? 'oneshot,encoder,decoder,damage'));
$cases = (int) ($opts['cases'] ?? 20000);
$seed = (int) ($opts['seed'] ?? 1);
$start = (int) ($opts['start'] ?? 0);
if (!Cli::available()) {
    fwrite(STDERR, "The zstd command-line tool is needed.\n");
    exit(2);
}

function generate(int $i, int $seed): string
{
    global $big;
    mt_srand($seed * 1000003 + $i);
    if ($big > 0 && mt_rand(1, $big) === 1) {
        // a large case of machine code (this PHP binary): the kind of input the 8.4/8.5 tracing JIT miscompilation needed (see the README)
        static $bin = null;
        $bin ??= (string) file_get_contents(PHP_BINARY, false, null, 0, 8 << 20);
        return substr($bin, mt_rand(0, strlen($bin) - 1), mt_rand(50000, 3000000));
    }
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


/** Decoder over the given chunks; null when it throws. */
function viaDecoder(array $chunks): ?string
{
    try {
        $d = new Decoder();
        $out = '';
        foreach ($chunks as $c) {
            $out .= $d->decode($c);
        }
        return $out . $d->finish();
    } catch (\UnexpectedValueException) {
        return null;
    }
}

function viaDecompress(string $zst): ?string
{
    try {
        return Zstd::decompress($zst);
    } catch (\UnexpectedValueException) {
        return null;
    }
}

/** Random chunking: sizes from one byte to a few hundred KB, with empty chunks now and then. */
function chunks(string $data, int $salt): array
{
    $pattern = ['random', 'tiny', 'empties', '64k', 'one'][mt_rand(0, 4)];
    return DecoderTest::split($data, $pattern, $salt);
}

function damaged(string $zst): string
{
    $n = strlen($zst);
    if ($n < 2) {
        return $zst . 'x';
    }
    if (mt_rand(0, 2) === 0) {
        return substr($zst, 0, mt_rand(0, $n - 1));
    }
    for ($k = mt_rand(1, 3); $k > 0; $k--) {
        $p = mt_rand(0, $n - 1);
        $zst[$p] = mt_rand(0, 1) ? chr(ord($zst[$p]) ^ (1 << mt_rand(0, 7))) : chr(mt_rand(0, 255));
    }
    return $zst;
}

$tmp = sys_get_temp_dir() . '/charm-zstd-fuzz-' . getmypid() . '.zst';
$fail = 0;
$bytesIn = 0;
$bytesOut = 0;
$paths = ['raw block' => 0, 'RLE block' => 0, 'literals only' => 0, 'raw literals' => 0, 'RLE literals' => 0, 'Huffman literals, 1 stream' => 0, 'Huffman literals, 4 streams' => 0,
    'predefined tables' => 0, 'RLE tables' => 0, 'FSE tables' => 0];
$t0 = microtime(true);
$counts = ['oneshot' => 0, 'encoder' => 0, 'encoder flushes' => 0, 'decoder' => 0, 'decoder, tool frames' => 0, 'damage' => 0, 'damage thrown' => 0];
$canCompress = Cli::available() && trim((string) shell_exec('zstd -q -1 -c < /dev/null 2>/dev/null | wc -c')) !== '0';
for ($i = $start; $i < $start + $cases; $i++) {
    $in = generate($i, $seed);
    $zst = Zstd::compress($in);
    $bytesIn += strlen($in);
    $bytesOut += strlen($zst);
    $problem = null;
    if (in_array('oneshot', $modes, true)) {
        ++$counts['oneshot'];
        try {
            if (Zstd::decompress($zst) !== $in) {
                $problem = 'this decoder returned other bytes';
            }
        } catch (Throwable $e) {
            $problem = 'this decoder: ' . $e->getMessage();
        }
        file_put_contents($tmp, $zst);
        $ref = shell_exec('zstd -q -d -c < ' . escapeshellarg($tmp) . ' 2>/dev/null');
        if ($ref !== $in && !($in === '' && ($ref === null || $ref === ''))) {
            $problem = ($problem ? $problem . '; ' : '') . 'zstd -d returned ' . ($ref === null ? 'nothing' : strlen((string) $ref) . ' other bytes');
        }
    }
    mt_srand($seed * 7000003 + $i);
    if ($problem === null && in_array('encoder', $modes, true)) {
        ++$counts['encoder'];
        $e = new Encoder();
        $d = new Decoder();
        $stream = '';
        $got = '';
        $pos = 0;
        $plain = [];
        foreach (chunks($in, $i) as $c) {
            $out = $e->encode($c);
            $pos += strlen($c);
            $plain[] = $c;
            if (mt_rand(0, 3) === 0) {
                $out .= $e->flush();
                ++$counts['encoder flushes'];
                $stream .= $out;
                $got .= $d->decode($out);
                if ($got !== substr($in, 0, $pos)) {
                    $problem = "after a flush at $pos of " . strlen($in) . ' the Decoder has ' . strlen($got) . ' bytes, not all the input so far';
                    break;
                }
            } else {
                $stream .= $out;
                $got .= $d->decode($out);
            }
        }
        if ($problem === null) {
            $tail = $e->finish();
            $stream .= $tail;
            $got .= $d->decode($tail) . $d->finish();
            if ($got !== $in) {
                $problem = 'encoder stream: the incremental Decoder returned other bytes';
            } elseif (viaDecompress($stream) !== $in) {
                $problem = 'encoder stream: decompress() returned other bytes or threw';
            } elseif (viaDecoder(chunks($stream, $i + 1)) !== $in) {
                $problem = 'encoder stream: the Decoder over random chunks returned other bytes or threw';
            } else {
                file_put_contents($tmp, $stream);
                $ref = shell_exec('zstd -q -d -c < ' . escapeshellarg($tmp) . ' 2>/dev/null');
                if ($ref !== $in && !($in === '' && ($ref === null || $ref === ''))) {
                    $problem = 'encoder stream: zstd -d returned ' . ($ref === null ? 'nothing' : strlen((string) $ref) . ' other bytes');
                }
            }
        }
        if ($problem === null) {
            // without flush(): the same bytes however the input is cut
            $e = new Encoder();
            $plainStream = '';
            foreach (chunks($in, $i + 2) as $c) {
                $plainStream .= $e->encode($c);
            }
            $plainStream .= $e->finish();
            $e = new Encoder();
            $other = $e->encode($in) . $e->finish();
            if ($plainStream !== $other) {
                $problem = 'encoder: the output depends on how the input is cut';
            }
        }
    }
    $frames = [$zst];
    if ($problem === null && in_array('decoder', $modes, true)) {
        ++$counts['decoder'];
        if (viaDecoder(chunks($zst, $i + 3)) !== $in) {
            $problem = 'Decoder over random chunks on compress() output returned other bytes or threw';
        } elseif ($canCompress && $i % 8 === 0) {
            ++$counts['decoder, tool frames'];
            file_put_contents($tmp, $in);
            $level = [-5, 1, 3, 9, 19][mt_rand(0, 4)];
            $extra = ['', ' --no-check', ' --long=27', ' -B4096', ' --no-content-size'][mt_rand(0, 4)];
            $tool = (string) shell_exec("zstd -q -f -$level$extra -c " . escapeshellarg($tmp) . ' 2>/dev/null');
            if ($tool !== '') {
                $frames[] = $tool;
                if (viaDecoder(chunks($tool, $i + 4)) !== $in) {
                    $problem = "Decoder over random chunks on a frame of zstd -$level$extra returned other bytes or threw";
                }
            }
        }
    }
    if ($problem === null && in_array('damage', $modes, true)) {
        foreach ($frames as $f) {
            ++$counts['damage'];
            $bad = damaged($f);
            $want = viaDecompress($bad);
            $have = viaDecoder(chunks($bad, $i + 5));
            if ($want !== $have) {
                $problem = 'damaged frame: decompress() ' . ($want === null ? 'throws' : 'returns ' . strlen($want) . ' bytes') . ', the Decoder '
                    . ($have === null ? 'throws' : 'returns ' . strlen($have) . ' bytes');
                break;
            }
            if ($want === null) {
                ++$counts['damage thrown'];
            }
        }
    }
    if ($problem !== null) {
        ++$fail;
        printf("FAIL case %d (seed %d, %d bytes): %s\n", $i, $seed, strlen($in), $problem);
        if ($fail >= 20) {
            break;
        }
        continue;
    }
    if (in_array('oneshot', $modes, true) && strlen($in) >= 16) {
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
echo 'modes: ', implode(', ', array_map(static fn ($k, $v) => "$k $v", array_keys($counts), $counts)), "\n";
echo 'first block of each case with 16+ bytes: ', implode(', ', array_map(static fn ($k, $v) => "$k $v", array_keys($paths), $paths)), "\n";
exit($fail > 0 ? 1 : 0);

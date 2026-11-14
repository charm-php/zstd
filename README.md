# charm/zstd

A Zstandard ([RFC 8878](https://www.rfc-editor.org/rfc/rfc8878)) decoder and a compressor in plain PHP (8.2+, no
dependencies, no extension). It exists for places where `ext-zstd` is not installed, for example reading Parquet files.

The decoder reads every frame that the reference compressor (`zstd`, tested with v1.5.5) writes without a dictionary, and
verifies content checksums. It takes an optional output limit, so that untrusted input cannot expand without bound. The
compressor is a single-pass one with Huffman-coded literals and FSE-coded sequence tables: its output is valid for every decoder and
its size is within 0 to 25 percent of `zstd -1` on the inputs measured below (see Compression).

```
composer require charm/zstd
```

## Usage

<!-- example: usage -->
```php
use Charm\Zstd\{DecodeException, Zstd};

$zst = Zstd::compress($data);
$data = Zstd::decompress($zst);

// Untrusted input whose size you know (a Parquet page, for example): refuse to produce more than that
try {
    $page = Zstd::decompress($compressedPage, maxSize: $uncompressedSize);
} catch (DecodeException $e) {
    // corrupt or truncated data, checksum mismatch, a dictionary frame, or more output than $uncompressedSize
}
```

## API

```php
namespace Charm\Zstd;

final class Zstd
{
    public static function compress(string $data): string;
    public static function decompress(string $data, ?int $maxSize = null): string;
}

final class DecodeException extends \RuntimeException {}
```

* `compress()` returns one frame (single segment, with the content size in the header, no checksum), Huffman-coded literals and FSE-coded sequence tables chosen per block by size (see Compression). The empty string
  gives a 9-byte frame. It keeps a hash table in a static variable between calls (an optimisation: stale entries are only
  candidates and are verified before use).
* `decompress()` accepts one frame or several concatenated frames, and skippable frames (which are skipped). It returns
  the concatenated content. The empty string is not valid input; a frame with empty content returns `''`.
* `$maxSize`: if not null, decoding throws `DecodeException` when the output would exceed that many bytes. It is checked
  when a frame header declares a content size larger than what is left of the limit (before any decoding), and once per
  block while decoding. A block produces at most 128 KiB, so the output held when the exception is thrown exceeds
  `$maxSize` by at most one block (128 KiB), however large the declared or real output is. The limit applies to the total
  over all frames. A negative `$maxSize` throws `\InvalidArgumentException` (a programming error, not bad input).
* `DecodeException` is the only exception thrown for any failure caused by the input: bad magic, truncated or corrupt data,
  a content checksum that does not match, a frame that needs a dictionary, output above `$maxSize`. PHP warnings and
  errors raised while decoding corrupt bytes are converted to it as well (the previous exception is attached when there is
  one), and the caller's error handler is not called for them. Out-of-memory from a huge but legal frame without a
  `$maxSize` is not something PHP can turn into an exception.

## What is supported

Decoder: raw, RLE and compressed blocks; raw, RLE, Huffman and treeless literals (1 and 4 streams); predefined, RLE, FSE and
repeat sequence tables; content checksums (XXH64, verified); the content size field (checked against the output);
concatenated frames; skippable frames. The window size is not enforced (the whole output is kept in memory anyway), so
frames made with `--long=27` or `--ultra -22` decode like any other.

Not supported:

* Dictionaries. A frame with a non-zero dictionary id throws `DecodeException`.
* Streaming: the input and the output are strings held in memory.
* The compressor is a fast one: a single hash table for 4-byte matches (16,384 entries), one repeat offset checked, no lazy matching, no
  treeless (repeated) Huffman tables or repeat sequence tables, always one frame with no checksum and no dictionary. Its size is
  about 10 percent above `zstd -1` on text and 20 percent on numbers and indices, and it never reaches `zstd -3` (see Compression).
  Its output decodes with `zstd -d` and with this decoder.

## Performance

Measured with `bench/bench.php` (through `bench/run.php`) on PHP 8.3.36 (opcache on), Linux 6.17, AMD Ryzen 9 9950X3D
(32 threads), `zstd` v1.5.5 for the reference frames. The machine was not idle (load average about 10 during the run,
another job was running), so treat differences below 15% as noise. Best of 7 runs after 2 warm-up runs; MB/s is
2^20 bytes of uncompressed data per second; every decoded result is compared with the original first.

Inputs: "source text" is 1.3 MB of generated PHP-like lines (fixed seed, built by `bench/bench.php`; very repetitive, ratio 7-16); "PHP binary" is the first 6 MiB of `php`; "synthetic words" is 700 000 words from a
vocabulary of 3000 random words; the two literal-heavy inputs are random decimal numbers separated by commas (3.0 MB) and bytes with a skewed
distribution (1.9 MB, hardly compressible: ratio 1.08).

Decoding, MB/s (ratio of the frame in parentheses):

| Input | JIT | `zstd -1` | `zstd -3` | `zstd -19` | `Zstd::compress()` output | native `gzdecode()` (level 6) |
|---|---|---:|---:|---:|---:|---:|
| source text 1.2 MB | tracing | 331 (7.26) | 367 (8.03) | 672 (15.77) | 279 (4.43) | 892 (6.78) |
| | off | 95 | 106 | 202 | 77 | 902 |
| PHP binary 5.6 MB | tracing | 134 (2.32) | 131 (2.54) | 118 (3.03) | 176 (1.97) | 404 (2.60) |
| | off | 48 | 46 | 40 | 55 | 406 |
| synthetic words 4.4 MB | tracing | 138 (2.44) | 141 (2.76) | 155 (3.04) | 140 (1.66) | 410 (2.44) |
| | off | 46 | 40 | 44 | 41 | 424 |
| literal-heavy: digits 3.0 MB | tracing | 170 (2.27) | 96 (2.02) | 163 (2.27) | 91 (1.24) | 499 (2.09) |
| | off | 65 | 29 | 61 | 26 | 490 |
| literal-heavy: skewed bytes 1.9 MB | tracing | 98 (1.08) | 98 (1.08) | 97 (1.08) | see below | 354 (1.08) |
| | off | 38 | 39 | 38 | see below | 356 |

Compression with `Zstd::compress()`, MB/s (ratio): 

| Input | tracing JIT | no JIT |
|---|---:|---:|
| source text | 83 (4.43) | 32 (4.43) |
| PHP binary | 40 (1.97) | 17 (1.97) |
| synthetic words | 42 (1.66) | 16 (1.66) |
| digits | 33 (1.24) | 13 (1.24) |
| skewed bytes | 6564 (1.00) | 3797 (1.00) |

The skewed-bytes input is the case where the compressor finds nothing and stores raw blocks, which is why its speed
(and the decoding of its output, 25 000 MB/s) is just copying. Without the JIT the decoder is roughly 2.5-3x slower. Native
`gzdecode()` is 1.3-5x faster than the decoder with the JIT and 4.5-17x faster without it (compare the ratio columns: gzip
level 6 compresses these inputs about as well as `zstd -3` on the binary and word inputs and worse on the generated text).
The decoder is slowest on literal-heavy frames (Huffman decoding is a loop per symbol in PHP); the `zstd -3` frame of the digits
input is much slower than the `-1` and `-19` frames of the same data, which was not investigated.

The `$maxSize` check was measured to cost nothing visible: decoding 5.6 MB at `zstd -3` took 41.6 ms without and 41.6 ms
with a limit (tracing JIT; 122 vs 122 ms without it).

Reproduce:

```
composer install
php bench/run.php                      # --runs=3 for a quick run
```

which runs `bench/bench.php` with `-d opcache.jit_buffer_size=128M -d opcache.jit=1255` and again with
`-d opcache.jit_buffer_size=0`. Without the `zstd` tool the reference-frame rows are skipped.

## Compression

`compress()` splits the input into blocks of up to 128 KiB. For each block it finds matches with a hash table of 4-byte sequences (and the
most recent offset), then writes:

* the **literals** (the bytes between matches) as the smallest of a raw, an RLE and a Huffman-coded section, by the size actually produced:
  a length-limited (11 bits) Huffman code built from the block's byte histogram, its tree described directly (4-bit weights, symbols up to 128)
  or FSE-compressed (any symbol), one stream below 256 literals and four with the jump table above, in the size format that fits. A histogram
  test skips the Huffman attempt when even an ideal code would save under 3 percent (random and already-compressed data), which is why incompressible input still costs
  one `count_chars()` per block and not a tree;
* the **sequences** with, for each of the literal-length, offset and match-length codes, a predefined, an RLE or an FSE-compressed table, whichever the estimated size of
  the block says is smallest (normalised counts written as RFC 8878 section 4.1.1 describes);
* a block with matches that make it larger as a raw block, a block that is one repeated byte as an RLE block, and a block with no match as literals only (zero sequences).

Measured with `bench/compress.php` (through `php bench/run.php --compress`; inputs of `bench/compress-inputs.php`, fixed seeds, 0.2 to 0.6 MB; `zstd` v1.5.5; PHP 8.5,
opcache on, tracing JIT; best of 5; MB/s of input). "Before" is the previous compressor (raw literals, predefined tables only). Sizes in bytes:

| Input | before | after | `zstd -1` | `zstd -3` | compress MB/s before / after (no JIT) | decode MB/s of our output before / after |
|---|---:|---:|---:|---:|---|---|
| text (words) 600,001 | 315,073 | 228,202 | 208,683 | 196,459 | 47.1 / 36.7 (17.9 / 15.4) | 138 / 132 |
| source-like code 600,013 | 248,173 | 179,407 | 149,971 | 144,625 | 51.5 / 43.3 (20.0 / 16.8) | 149 / 147 |
| words 600,000 | 320,486 | 231,997 | 211,755 | 199,700 | 46.5 / 36.5 (17.7 / 15.1) | 134 / 130 |
| digits, one number per line 600,001 | 486,726 | 313,090 | 264,468 | 292,295 | 31.0 / 22.3 (11.7 / 10.4) | 94 / 90 |
| JSON records 600,047 | 101,433 | 74,174 | 65,432 | 66,851 | 102 / 87 (39.4 / 34.3) | 418 / 385 |
| mixed binary 236,000 | 103,817 | 85,325 | 85,370 | 80,166 | 67.1 / 43.5 (27.0 / 20.9) | 293 / 170 |
| PLAIN int64 (rising counter), 600,000 | 290,772 | 158,863 | 158,899 | 158,848 | 44.3 / 35.2 (16.5 / 15.8) | 176 / 212 |
| dictionary indices (small bytes), 600,000 | 481,992 | 314,782 | 258,294 | 295,202 | 30.1 / 21.4 (11.9 / 10.3) | 96 / 90 |
| random bytes 200,000 | 200,015 | 200,015 | 200,019 | 200,019 | 5442 / 2209 (3112 / 1655) | 43,448 / 38,377 |

Each step separately (JIT, sizes in bytes, MB/s): Huffman literals alone take the text from 315,073 to 303,378 bytes (46 to 38.5 MB/s), the digits from 486,726 to 439,112 (31 to 24) and
the dictionary indices from 481,992 to 428,623 (30 to 23); they gain nothing on int64 (the literals are close to uniform, the histogram test skips them) and cost under 6 percent of its speed there. FSE-coded sequence
tables then bring the text to 228,202 (42 MB/s), the digits to 313,090, the int64 to 158,863: they are where most of the gain is, because the predefined
distributions fit the sequences of real data poorly. The compressor is 15 to 40 percent slower than before (up to 2.4x on random data, where it is still 2 GB/s: one histogram per block), and
the decoder, on our output, is within 5 percent of its old speed on text and 6 to 42 percent slower on the inputs that are now Huffman-coded and no longer mostly raw literals (mixed binary; the output is also 18 percent smaller);
on the int64 pages it is faster, because the output is half the size. A 64K-entry hash table was tried and rejected: 2 percent smaller output for a 10 to 30 percent slower int64 compression.

## Limitations

* Pure PHP: 1.3-17x slower than native `gzdecode()` on the same data (table above), and much slower than `ext-zstd` (not measured here: the extension is not installed on the test machine).
* No dictionaries, no streaming, no window-size limit; the whole input and output live in memory. Use `$maxSize` for untrusted input.
* **PHP 8.4 and 8.5 with the tracing JIT** (`opcache.jit=tracing`, the default JIT mode): the JIT miscompiles the
  decoder's bitstream refill loops, so earlier versions of this library threw "Corrupted Zstandard sequence" on valid
  frames there. This is a PHP JIT bug, fixed on php-src master (8.7) by its IR update for dstogov/ir GH-302 but
  not in 8.4 to 8.6 as of November 2026. On PHP 8.4 and later the decoder refills
  with one 8-byte read instead of a loop. That is correct with every JIT setting, but 8.4/8.5's tracing JIT then fails
  to compile the decoder's main loop and runs it in the interpreter: decoding there is about as fast as without the JIT,
  44-81 MB/s, against 230-340 MB/s with PHP 8.3's tracing JIT (1 MB of text, `zstd -1` to `-19` frames). PHP 8.3 keeps
  the loop and is not affected. `tests/TracingJitTest.php` decodes in a child process with the tracing JIT on.
* The compressor's ratio is below the real tool's: 9 percent above `zstd -1` on text, 20 percent on digits and dictionary indices, equal on int64 pages and binary, and 16 to 25 percent above `zstd -3` on text (see Compression). The older Performance tables above were measured with the previous, weaker compressor (ratios 4.43 on the source text, 1.97 on the PHP binary, 1.66 on words) and are kept for the decoder rows.
* Only compatible with what was tested: frames from `zstd` 1.5.5 at the settings listed under Tests. Other encoders' frames should decode if they follow the RFC, but none were tested.
* The window size and the format's checks that depend on it (for example, an offset larger than the window) are not enforced; a match offset is only checked against the output produced so far.

## Tests

```
composer install
vendor/bin/phpunit
```

* `FixtureTest`: frames written by the real tool, committed in `tests/fixtures/` (about 1.4 MB), decoded and compared with
  plaintext that is regenerated deterministically by `tests/Inputs.php` (and checked against a SHA-256 in `manifest.json`).
  Settings: `--fast=5`, `-1`, `-3`, `-19`, `--ultra -22`, `--long=27`, with and without `--check`, `--no-compress-literals`,
  `--no-content-size`, `-B4096`, piped input (no content size), concatenated frames, skippable frames, an empty frame. These
  run without the `zstd` binary. `php tests/fixtures/generate.php` regenerates them with the tool.
* `CliTest` (skipped with a message when `zstd` is not on PATH): our compressor's output through `zstd -d`; the tool's
  output at 23 settings through our decoder; both over the same 86 inputs around every boundary (15/16/17, 255/256/257,
  65535..65792, 131071..131073, multi-block, text, binary, random, long runs, periods, low entropy).
* `EntropyTest`: the new paths on their own: literals of 1, 2, 3, 4 distinct bytes, 256 values uniformly (must stay raw), a Fibonacci distribution whose optimal code is 23 bits deep (limited to 11),
  symbols above 128 (the FSE-compressed tree), a long tail of weights, literal sections of 31 to 131,072 bytes around every size-format boundary (each checks the format and the regenerated size in the header),
  a block of literals only and one of exactly 128 KiB, tables chosen per block (predefined, RLE, FSE), normalised counts; each decoded here and by `zstd -d`. `tests/fixtures/ours-*.zst` are the compressor's own output
  (accepted by `zstd -d` when written), so the tests that do not run the tool still decode it.
* `php tests/tools/fuzz.php`: random inputs (size 0 to 300,000, alphabets of 1 to 256 symbols, skew, copies from anywhere earlier, runs, noise) through `compress()`, each decoded by this decoder and by `zstd -d`: 20,000 cases per seed, fixed seeds, 0 failures for seeds 1 and 2
  (40,000 cases, 779 MB in).
* Fault injection: 16 faults in the new encoder code (streams written in the wrong order, a wrong canonical code, swapped jump table sizes, a wrong segment size, a size-format threshold, the direct weight packing, a 12-bit limit, a zero normalised count, the long form of a count, a wrong accuracy log for a final state, swapped modes,
  the entropy gate, an RLE table symbol, a missing zero sequence count, the order of the two weight states, no Kraft repair after limiting): each is caught by a named test (`EntropyTest`, `CliTest`, `CompressTest`, `StateTest`).
* `ReadmeExamplesTest`: the README's PHP example is run, and its signature listing is compared with the class by reflection.
* `CompressTest`, `StateTest`: round trips, and 400 interleaved compressions in one process (the static hash table), each
  frame checked by our decoder and, when available, `zstd -d`.
* `CorruptionTest`: truncations and bit flips with fixed seeds, with and without checksum, plus every truncation and every
  single-bit flip of two small frames. The only outcomes allowed are `DecodeException` or a returned string, with a
  checksum that string must be the original; a PHP warning fails the test.
* `MaxSizeTest`: exact size passes and one byte less throws for frames with and without content size and for
  concatenated frames; a declared size above the limit is refused before decoding; a 64 MiB bomb of RLE blocks and one of 64 compressed
  frames are rejected within a second and without allocating the output; the one-block overshoot bound.
* `DecompressTest`: handwritten frames and 27 kinds of bad input.

CI (`.github/workflows/tests.yml`) runs PHP 8.2 to 8.5. The `zstd` binary is not required by it: the cross-checks are skipped when it is missing.
(PHPUnit 11 needs `ext-mbstring`; the 8.3 on the development machine has none, so the suite was run with 8.5 and, through a stub runner, with 8.3.)

## License

MIT

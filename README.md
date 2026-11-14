# charm/zstd

A Zstandard ([RFC 8878](https://www.rfc-editor.org/rfc/rfc8878)) decoder and a compressor in plain PHP (8.2+, no
dependencies, no extension). It exists for places where `ext-zstd` is not installed, for example reading Parquet files.

The decoder reads every frame that the reference compressor (`zstd`, tested with v1.5.5) writes without a dictionary, and
verifies content checksums. It takes an optional output limit, so that untrusted input cannot expand without bound. A streaming `Decoder` and
`Encoder` take their input in chunks of any size (`decode()` / `encode()` / `finish()`, the shape of charm/jsonl and charm/csv). The
compressor is a single-pass one with Huffman-coded literals and FSE-coded sequence tables: its output is valid for every decoder and
its size is within 0 to 25 percent of `zstd -1` on the inputs measured below (see Compression).

```
composer require charm/zstd
```

## Usage

<!-- example: usage -->
```php
use Charm\Zstd\Zstd;

$zst = Zstd::compress($data);
$data = Zstd::decompress($zst);

// Untrusted input whose size you know (a Parquet page, for example): refuse to produce more than that
try {
    $page = Zstd::decompress($compressedPage, maxSize: $uncompressedSize);
} catch (\UnexpectedValueException $e) {
    // corrupt or truncated data, checksum mismatch, a dictionary frame, or more output than $uncompressedSize
}
```

Streaming: read, decode, and call `finish()` at the end; encode with a `fwrite()` loop.

<!-- example: streaming -->
```php
use Charm\Zstd\{Decoder, Encoder};

// Decode a stream
$decoder = new Decoder();
$h = fopen('data.zst', 'rb');
while (!feof($h)) {
    echo $decoder->decode(fread($h, 65536));   // the output of the blocks this chunk completed (possibly '')
}
echo $decoder->finish();                       // throws if the stream is incomplete

// Encode to a stream
$encoder = new Encoder();
$out = fopen('copy.zst', 'wb');
$in = fopen('data.zst.plain', 'rb');
while (!feof($in)) {
    fwrite($out, $encoder->encode(fread($in, 65536)));
}
fwrite($out, $encoder->finish());              // the last block and the checksum
```

## API

```php
namespace Charm\Zstd;

final class Zstd
{
    public static function compress(string $data): string;
    public static function decompress(string $data, ?int $maxSize = null): string;
}

final class Decoder
{
    public function __construct(?int $maxSize = null);
    public function decode(string $chunk): string;     // the output the chunk completed
    public function finish(): string;                  // end of input: throws if incomplete; resets the decoder
}

final class Encoder
{
    public function __construct();
    public function encode(string $chunk): string;     // the compressed bytes that became final
    public function flush(): string;                   // ends the current block: a Decoder can output everything given so far
    public function finish(): string;                  // ends the stream; resets the encoder
}
```

* `compress()` returns one frame (single segment, with the content size in the header, no checksum), Huffman-coded literals and FSE-coded sequence tables chosen per block by size (see Compression). The empty string
  gives a 9-byte frame. It keeps a hash table in a static variable between calls (an optimisation: stale entries are only
  candidates and are verified before use).
* `decompress()` accepts one frame or several concatenated frames, and skippable frames (which are skipped). It returns
  the concatenated content. The empty string is not valid input; a frame with empty content returns `''`.
* `$maxSize`: if not null, decoding throws `\UnexpectedValueException` when the output would exceed that many bytes. It is checked
  when a frame header declares a content size larger than what is left of the limit (before any decoding), and once per
  block while decoding. A block produces at most 128 KiB, so the output held when the exception is thrown exceeds
  `$maxSize` by at most one block (128 KiB), however large the declared or real output is. The limit applies to the total
  over all frames. A negative `$maxSize` throws `\ValueError` (a programming error, not bad input).
* `Decoder` takes input in chunks of any size and alignment (1 byte, empty, cut inside a block header) and returns the output that became available.
  `Decoder(maxSize:)` has the semantics of `decompress($data, $maxSize)` over the whole stream.
* `Encoder` writes one frame without a content size, with a 128 KiB window, blocks of 128 KiB and a content checksum.
* **Chunking never changes the result.** For every split of a valid stream, the `decode()` results joined with `finish()` equal `decompress()`; for every invalid
  stream the `Decoder` throws (in `decode()` or in `finish()`) exactly when `decompress()` does. `Encoder` without `flush()` writes the same bytes
  whatever the chunking of its input.
* **Granularity.** A `Decoder` holds the input until a whole block (at most 128 KiB) is present, then decodes it and returns its output: output arrives a block at
  a time, and `decode()` returns `''` for a chunk that completes no block. Skippable frames are skipped without being held. An `Encoder` compresses a block as soon as 128 KiB are pending,
  so `encode()` returns `''` until then; `flush()` compresses the pending input as a block of its own (a smaller block: flushing after every small chunk costs ratio).
* **Memory.** A `Decoder` keeps the window of the frame (its `Window_Size`, or the content size of a single-segment frame), trimmed in large steps (at least 1 MiB or half a window), plus the block being received:
  not the whole output. An `Encoder` keeps the last 128 KiB it compressed and the pending input, at most about 2.3 MiB in all.
* `finish()` ends the input. `Decoder::finish()` throws if the stream is incomplete (no input at all is incomplete, as for `decompress('')`); both reset the object for another stream.
* After an exception from a `Decoder`, discard it.

## Errors

Misuse throws PHP's own `\ValueError` (a negative `$maxSize`). Bad input throws `\UnexpectedValueException` with a message naming Zstandard: bad magic,
truncated or corrupt data, a content checksum that does not match, a frame that needs a dictionary, a match that reaches back beyond the window, output above `$maxSize`.
There is no exception class of this package, so `catch (\UnexpectedValueException)` catches a data error from any charm package. PHP warnings and errors raised while decoding corrupt bytes are converted to it as well (the previous exception is attached when there is
one), and the caller's error handler is not called for them. Out-of-memory from a huge but legal frame without a `$maxSize` is not something PHP can turn into an exception.

## What is supported

Decoder: raw, RLE and compressed blocks; raw, RLE, Huffman and treeless literals (1 and 4 streams); predefined, RLE, FSE and
repeat sequence tables; content checksums (XXH64, verified); the content size field (checked against the output);
concatenated frames; skippable frames. The window size limits only how far a match may reach back (see Limitations): frames made with
`--long=27` or `--ultra -22` decode like any other, and no limit is put on the window size itself.

Not supported:

* Dictionaries. A frame with a non-zero dictionary id throws `\UnexpectedValueException`.
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

### Streaming

`Decoder` fed 64 KiB chunks and `Encoder` fed 64 KiB chunks, from the same run (best of 5, PHP 8.3.36, tracing JIT; without the JIT in parentheses; the machine was busy, so differences below 10% are noise). The `Decoder` is
within 1-3% of `decompress()` on the same frame on every input but one: the source text, whose `zstd -3` frame has a ratio of 8 and many sequences per block, takes 296 MB/s (100) against 365 (108), 19% (8%) slower.
Streaming decoding of `Zstd::compress()` output: 247 vs 249 MB/s on the source text, 120 vs 121 on the PHP binary, 111 vs 114 on the words, 88 vs 88 on the digits. The `Encoder` writes the same sizes as
`compress()` (ratio 5.71, 2.37, 2.30, 1.92 and 1.08 on the five inputs, the same to two decimals) at the same speed (55 vs 53 MB/s on the source text, 21 vs 21 on the binary; 13 to 26 MB/s without the JIT), and `decompress()` reads its streams as fast as
`compress()`'s (247 vs 249 MB/s on the source text). `decompress()` itself was measured before and after this change, back to back: within 2% on every row with the JIT, and within 1-2% without it in a separate 25-run test (the extra
window check costs nothing visible).

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
* No dictionaries, and no limit on the window size: a frame may declare a 3.75 TB window, and `Decoder` then holds as much output as the frame produces (use `$maxSize` for untrusted input). `decompress()` keeps the whole input and output in memory.
* **PHP 8.4 and 8.5 with the tracing JIT** (`opcache.jit=tracing`, the default JIT mode; 8.6 and later were not tested): the JIT miscompiles code
  of this library in two ways, and this library works around both.
  * *The sequence loop of the block decoder* (`Engine::decodeBlock()`). The trace of the loop is compiled after about 60 iterations (`opcache.jit_hot_loop`),
  and the compiled loop then computes a wrong FSE state, so a frame fails with "Corrupted Zstandard sequence" or decodes to wrong bytes. It depends on the data: 100 KB of machine code
  (the PHP binary) at `zstd -1` or `-3` failed; 2 MB of generated text, binary tables and the fuzzer's small inputs did not. An earlier version of this README said that
  the refill loops were the problem and that reading 8 bytes at a time avoided it; that held only for small frames (the tests then only had small ones). What is known: it happens with `opcache.jit=1253` and above (the optimisation levels that use type inference), not with
  `jit=function`, `1205` or `1252`; the first state that differs is in the iteration where the compiled trace starts (the 62nd sequence of the first block of the failing frame); the failure stays when the refill expression is rewritten (7-byte reads, other masks), and
  restructuring the refill branches changed whether it failed (one variant decoded the test frames on 8.5 and failed on 8.4), so no shape of the source was found that is safely free of it. It is not the loop's
  arithmetic or the side exits (it happens with `opcache.jit_max_side_traces=0`). The IR of the failing trace was not analysed further. PHP 8.3 is not affected, and PHP master (8.7), where an update of the JIT's IR library
  (dstogov/ir GH-302) landed, was not tested.
  *Workaround:* on PHP 8.4 and later `Engine::init()` calls `opcache_jit_blacklist()` for `decodeBlock()`, so the JIT never compiles that function (nothing changes without opcache: no JIT, no function).
  Everything else is still compiled, notably the Huffman literal decoders. The cost: on 8.4/8.5 decoding runs at about 20-110 MB/s
  (the tables above, which are 8.3's, are 3-4x faster on these inputs: 8.3's tracing JIT compiles the loop correctly, 133-360 MB/s) and at 160-180 MB/s on the two
  Huffman-heavy frames of the digits input.
  * *Assigning to the array that a `foreach` iterates over* (the Huffman code length limiting in the compressor did: `$lens[$s] = $limit` in `foreach ($lens as $s => $l)`). The JIT keeps writing to the
  array the loop started with, the assignment is lost and the output frame has an invalid Huffman tree. Found by the fuzzer with large inputs; `Zstd::compress()` and the `Encoder` iterate over another array now. Nothing else in the library does this.
* `tests/TracingJitTest.php` runs child processes with the tracing JIT on: a 56 KB block of machine code that failed (`tests/fixtures/jit-block.zst`), 5.5 MB of data (the first 3 MB of the PHP binary, generated text and skewed bytes) compressed by the tool at five settings, by `compress()` and by the `Encoder` and decoded by `decompress()` and the `Decoder`, and 600 skewed inputs for the
  Huffman length limiting. Both big tests fail on 0.1.0-RC1 on PHP 8.4 and 8.5. `php tests/tools/fuzz.php --big=100` adds large machine-code cases to the fuzzer; it found the second bug.
* The compressor's ratio is below the real tool's: 9 percent above `zstd -1` on text, 20 percent on digits and dictionary indices, equal on int64 pages and binary, and 16 to 25 percent above `zstd -3` on text (see Compression). The older Performance tables above were measured with the previous, weaker compressor (ratios 4.43 on the source text, 1.97 on the PHP binary, 1.66 on words) and are kept for the decoder rows.
* Only compatible with what was tested: frames from `zstd` 1.5.5 at the settings listed under Tests. Other encoders' frames should decode if they follow the RFC, but none were tested.
* A match offset larger than the frame's window (or than the output produced so far) is rejected, as RFC 8878 requires and as the streaming `Decoder`, which keeps only the window, needs. A block larger than `min(Window_Size, 128 KiB)` is not rejected.

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
* `php tests/tools/fuzz.php` (modes `oneshot,encoder,decoder,damage`; `--modes=oneshot` for the compressor alone): random inputs (size 0 to 300,000, alphabets of 1 to 256 symbols, skew, copies from anywhere earlier, runs, noise) through `compress()`, each decoded by this decoder and by `zstd -d`: 20,000 cases per seed, fixed seeds, 0 failures for seeds 1 and 2
  (40,000 cases, 779 MB in). With the streaming modes (the same inputs through `Encoder` with about 200 `flush()` calls per case, `Decoder` over random chunks, 2,500 frames of the tool at levels -5 to 19 per seed, and 22,000 truncated or damaged frames per seed that the `Decoder` must reject exactly when `decompress()` does), also 0 failures for seeds 1 and 2, on PHP 8.5 and on PHP 8.3, both with the tracing JIT.
* Fault injection: 16 faults in the new encoder code (streams written in the wrong order, a wrong canonical code, swapped jump table sizes, a wrong segment size, a size-format threshold, the direct weight packing, a 12-bit limit, a zero normalised count, the long form of a count, a wrong accuracy log for a final state, swapped modes,
  the entropy gate, an RLE table symbol, a missing zero sequence count, the order of the two weight states, no Kraft repair after limiting): each is caught by a named test (`EntropyTest`, `CliTest`, `CompressTest`, `StateTest`).
* `ReadmeExamplesTest`: the README's PHP examples (one-shot and streaming) are run, and its signature listing is compared with the classes by reflection.
* `DecoderTest`: every fixture through the `Decoder` with six split patterns (1 byte, 64 KiB, seeded random sizes, 1-9 bytes, one chunk, empty chunks interleaved), compared with `decompress()`; truncated and damaged frames through random splits throw exactly when `decompress()` does; `$maxSize` (exact, one byte less, across frames, a bomb fed in small chunks); a 70 MiB stream and a 66 MiB stream with long matches decoded in 2 KiB / 64 KiB chunks with a peak memory of a few MiB; an offset beyond the window.
* `EncoderTest`: the stream through `decompress()`, the `Decoder` and `zstd -d` for all 86 inputs; the same bytes for every chunking; `flush()` at random points (a `Decoder` has all the input given so far); `finish()` reuse; empty input; matches at distances up to the 128 KiB window; ratio against `compress()`.
* `CompressTest`, `StateTest`: round trips, and 400 interleaved compressions in one process (the static hash table), each
  frame checked by our decoder and, when available, `zstd -d`.
* `CorruptionTest`: truncations and bit flips with fixed seeds, with and without checksum, plus every truncation and every
  single-bit flip of two small frames. The only outcomes allowed are `\UnexpectedValueException` or a returned string, with a
  checksum that string must be the original; a PHP warning fails the test.
* `MaxSizeTest`: exact size passes and one byte less throws for frames with and without content size and for
  concatenated frames; a declared size above the limit is refused before decoding; a 64 MiB bomb of RLE blocks and one of 64 compressed
  frames are rejected within a second and without allocating the output; the one-block overshoot bound.
* `DecompressTest`: handwritten frames and 27 kinds of bad input.

CI (`.github/workflows/tests.yml`) runs PHP 8.2 to 8.5. The `zstd` binary is not required by it: the cross-checks are skipped when it is missing.
(PHPUnit 11 needs `ext-mbstring`; the 8.3 on the development machine has none, so the suite was run with 8.5 and, through a stub runner, with 8.3.)

## License

MIT

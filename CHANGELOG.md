# Changelog

## [0.1.0-RC2]
### Added
- `Decoder`: an incremental decompressor. `decode($chunk)` returns the output the chunk completed (a block at a time), `finish()` ends the input (throws
  if the stream is incomplete) and resets the decoder. Chunking never changes the result; the same strictness as `decompress()`; `maxSize:` counts the output of the whole stream;
  keeps only the window of the frame, not the whole output; skippable frames are skipped without being held.
- `Encoder`: an incremental compressor. `encode($chunk)`, `flush()` (ends the current block, so that a `Decoder` has all the input so far) and `finish()` (resets the encoder).
  One frame without content size, a 128 KiB window, blocks of 128 KiB and a content checksum; the output does not depend on how the input is cut into chunks (without `flush()`).
- A README "Errors" section; streaming examples; streaming numbers in Performance; streaming modes in `tests/tools/fuzz.php`; `DecoderTest`, `EncoderTest`.
### Fixed
- **PHP 8.4 and 8.5 with the tracing JIT: `decompress()` and `Decoder` failed on frames of real data** ("Corrupted Zstandard sequence"; already in RC1; 100 KB of machine code at `zstd -1` was enough): the JIT miscompiles the sequence loop of the block decoder
  once its trace is compiled. `decodeBlock()` is now excluded from the JIT (`opcache_jit_blacklist()`, PHP 8.4+); decoding there runs at interpreter speed for that loop (about 20-110 MB/s).
- The same JITs lost the code length clamp of the Huffman tree builder (assignment to the array a `foreach` iterates), so `compress()` / `Encoder` wrote invalid frames for very skewed literals after many calls.
- `TracingJitTest` now decodes real, large data in a child process (it missed both); `fuzz.php --big=N`.
### Changed
- **Breaking:** `DecodeException` is removed. Bad input throws `\UnexpectedValueException` with a message naming Zstandard (the exception policy of the charm packages);
  a negative `$maxSize` throws `\ValueError` instead of `\InvalidArgumentException`.
- A match offset larger than the frame's window is now rejected (RFC 8878), by `decompress()` too: the streaming decoder keeps only the window.
- The decoder's block loop moved to `Charm\Zstd\Internal\Engine` (with the compressor's block encoder); `Zstd::compress()` output is byte for byte the same, and the speed of `decompress()` is unchanged.

## [0.1.0-RC1]
First release candidate.
### Added
- `Zstd::decompress($data, maxSize:)`: a Zstandard (RFC 8878) decoder for every frame without a dictionary (raw, RLE and
  compressed blocks; all literal and sequence modes; content checksums; concatenated and skippable frames), with an output limit
  for untrusted input. Checked against frames from `zstd` 1.5.5 and against Parquet files written by pyarrow (levels -7 to 22) and DuckDB.
- `Zstd::compress($data)`: a single-pass compressor whose output is about 10 to 25 percent larger than `zstd -1`.

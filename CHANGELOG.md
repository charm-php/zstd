# Changelog

## [0.1.0-RC1]
First release candidate.
### Added
- `Zstd::decompress($data, maxSize:)`: a Zstandard (RFC 8878) decoder for every frame without a dictionary (raw, RLE and
  compressed blocks; all literal and sequence modes; content checksums; concatenated and skippable frames), with an output limit
  for untrusted input. Checked against frames from `zstd` 1.5.5 and against Parquet files written by pyarrow (levels -7 to 22) and DuckDB.
- `Zstd::compress($data)`: a single-pass compressor whose output is about 10 to 25 percent larger than `zstd -1`.

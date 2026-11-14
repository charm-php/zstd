<?php

declare(strict_types=1);

namespace Charm\Zstd;

/**
 * Thrown by Zstd::decompress() for every failure caused by the input: bad magic, corrupt or truncated data, a checksum
 * mismatch, a frame that needs a dictionary, or output that would exceed the size limit.
 */
final class DecodeException extends \RuntimeException
{
}

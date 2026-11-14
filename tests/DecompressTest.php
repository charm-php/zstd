<?php

declare(strict_types=1);

namespace Charm\Zstd\Tests;

use Charm\Zstd\DecodeException;
use Charm\Zstd\Zstd;
use PHPUnit\Framework\TestCase;

/** Handwritten frames and every kind of input the decoder has to refuse. */
final class DecompressTest extends TestCase
{
    private const MAGIC = "\x28\xB5\x2F\xFD";

    public function testDecodeExceptionIsARuntimeException(): void
    {
        $this->assertInstanceOf(\RuntimeException::class, new DecodeException());
    }

    public function testEmptyFrame(): void
    {
        // single segment, 1-byte content size 0, one empty last raw block
        $this->assertSame('', Zstd::decompress(self::MAGIC . "\x20\x00\x01\x00\x00"));
    }

    public function testRawBlockWithoutContentSize(): void
    {
        // window descriptor present (no single segment), no content size, raw last block of 5 bytes
        $this->assertSame('hello', Zstd::decompress(self::MAGIC . "\x00\x00" . "\x29\x00\x00" . 'hello'));
    }

    public function testRleBlock(): void
    {
        // single segment, content size 10, last RLE block of 10 bytes
        $this->assertSame(str_repeat('z', 10), Zstd::decompress(self::MAGIC . "\x20\x0A" . "\x53\x00\x00" . 'z'));
    }

    public function testOnlySkippableFramesDecodeToTheEmptyString(): void
    {
        $this->assertSame('', Zstd::decompress("\x50\x2A\x4D\x18" . pack('V', 3) . 'abc'));
        $this->assertSame('', Zstd::decompress("\x5F\x2A\x4D\x18" . pack('V', 0)));
    }

    /** @return array<string, array{string}> */
    public static function badInputs(): array
    {
        $m = self::MAGIC;
        return [
            'empty string' => [''],
            'one byte' => ["\x28"],
            'magic only' => [$m],
            'wrong magic' => ['not zstd at all'],
            'gzip' => ["\x1f\x8b\x08\x00\x00\x00\x00\x00"],
            'magic and descriptor only' => [$m . "\x20"],
            'header cut short (4-byte size)' => [$m . "\xA0\x01\x02"],
            'header cut short (8-byte size)' => [$m . "\xE0\x01\x02\x03\x04"],
            'header cut short (window, dict id)' => [$m . "\x03\x00"],
            'reserved bit set' => [$m . "\x28\x00\x01\x00\x00"],
            'no block header' => [$m . "\x20\x00"],
            'block header cut' => [$m . "\x20\x00\x01\x00"],
            'raw block cut' => [$m . "\x20\x05\x29\x00\x00hel"],
            'rle block without byte' => [$m . "\x20\x05\x2B\x00\x00"],
            'reserved block type' => [$m . "\x20\x05\x07\x00\x00xxxxx"],
            'compressed block of size 1' => [$m . "\x20\x00\x0D\x00\x00\x00"],
            'compressed block cut' => [$m . "\x20\x00\x35\x00\x00\x00\x00"],
            'content size does not match' => [$m . "\x20\x06\x29\x00\x00hello"],
            'block over 128 KiB' => [$m . "\x20\x00" . "\x09\x00\x10" . str_repeat('a', 131080)],
            'checksum missing' => [$m . "\x24\x05\x29\x00\x00hello"],
            'checksum cut' => [$m . "\x24\x05\x29\x00\x00hello\x00\x00"],
            'checksum wrong' => [$m . "\x24\x05\x29\x00\x00hello\x00\x00\x00\x00"],
            'garbage after a frame' => [$m . "\x20\x00\x01\x00\x00junk"],
            'one byte after a frame' => [$m . "\x20\x00\x01\x00\x00\x00"],
            'skippable frame cut' => ["\x50\x2A\x4D\x18" . pack('V', 10) . 'abc'],
            'skippable header cut' => ["\x50\x2A\x4D\x18\x01\x00"],
            'content size above PHP_INT_MAX' => [$m . "\xE0" . str_repeat("\xFF", 8) . "\x01\x00\x00"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badInputs')]
    public function testBadInputThrowsDecodeException(string $input): void
    {
        $this->expectException(DecodeException::class);
        Zstd::decompress($input);
    }

    public function testChecksumOfAHandwrittenFrame(): void
    {
        $sum = substr(strrev(hash('xxh64', 'hello', true)), 0, 4);
        $this->assertSame('hello', Zstd::decompress(self::MAGIC . "\x24\x05\x29\x00\x00hello" . $sum));
        $this->expectException(DecodeException::class);
        $this->expectExceptionMessageMatches('/checksum/');
        Zstd::decompress(self::MAGIC . "\x24\x05\x29\x00\x00hellp" . $sum);
    }

    public function testFrameThatNeedsADictionary(): void
    {
        // single segment + 1-byte dictionary id (5) + 1-byte content size 0 + an empty last raw block
        $this->expectException(DecodeException::class);
        $this->expectExceptionMessageMatches('/dictionary/');
        Zstd::decompress(self::MAGIC . "\x21\x05\x00\x01\x00\x00");
    }

    public function testDictionaryIdZeroIsNotADictionary(): void
    {
        $this->assertSame('', Zstd::decompress(self::MAGIC . "\x21\x00\x00\x01\x00\x00"));
    }

    public function testFourByteDictionaryId(): void
    {
        $this->expectException(DecodeException::class);
        Zstd::decompress(self::MAGIC . "\x23\x01\x02\x03\x04\x00\x01\x00\x00");
    }

    public function testNegativeMaxSizeIsAProgrammingError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Zstd::decompress(self::MAGIC . "\x20\x00\x01\x00\x00", -1);
    }

    public function testPhpWarningsAreNotLeakedToTheCallersHandler(): void
    {
        $seen = [];
        set_error_handler(static function (int $no, string $str) use (&$seen): bool {
            $seen[] = $str;
            return true;
        });
        try {
            foreach (self::badInputs() as [$input]) {
                try {
                    Zstd::decompress($input);
                } catch (DecodeException) {
                }
            }
        } finally {
            restore_error_handler();
        }
        $this->assertSame([], $seen);
    }

    public function testCallersErrorHandlerIsRestored(): void
    {
        $mine = static fn (): bool => true;
        set_error_handler($mine);
        try {
            try {
                Zstd::decompress('garbage');
            } catch (DecodeException) {
            }
            $this->assertSame($mine, set_error_handler(static fn (): bool => true));
            restore_error_handler();
        } finally {
            restore_error_handler();
        }
    }
}

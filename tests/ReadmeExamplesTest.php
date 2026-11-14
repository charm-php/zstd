<?php

declare(strict_types=1);

namespace Charm\Zstd\Tests;

use Charm\Zstd\Zstd;
use PHPUnit\Framework\TestCase;

/** The PHP example of the README marked `<!-- example: usage -->` is run; the signature listing is checked against the class. */
final class ReadmeExamplesTest extends TestCase
{
    private static function readme(): string
    {
        return (string) file_get_contents(__DIR__ . '/../README.md');
    }

    public function testUsageExampleRuns(): void
    {
        $this->assertSame(1, preg_match('/<!-- example: usage -->\n```php\n(.*?)\n```/s', self::readme(), $m));
        $data = Inputs::text(5000, 1);
        $compressedPage = Zstd::compress($data);
        $uncompressedSize = strlen($data);
        $code = $m[1];
        (static function () use ($code, $data, $compressedPage, $uncompressedSize): void {
            eval($code);
        })();
        $this->addToAssertionCount(1);
    }

    public function testUsageExampleRefusesALargerPageAsTheCommentSays(): void
    {
        $this->assertSame(1, preg_match('/<!-- example: usage -->\n```php\n(.*?)\n```/s', self::readme(), $m));
        $data = Inputs::text(5000, 1);
        $code = str_replace('// corrupt or truncated data', 'throw $e; // corrupt or truncated data', $m[1]);
        $compressedPage = Zstd::compress($data);
        $uncompressedSize = strlen($data) - 1;
        $this->expectException(\Charm\Zstd\DecodeException::class);
        (static function () use ($code, $data, $compressedPage, $uncompressedSize): void {
            eval($code);
        })();
    }

    public function testSignatureListingNamesRealMethods(): void
    {
        $this->assertSame(1, preg_match('/```php\nnamespace Charm\\\\Zstd;\n\nfinal class Zstd\n\{(.*?)\n\}/s', self::readme(), $m));
        preg_match_all('/public static function (\w+)\(([^)]*)\): (\w+);/', $m[1], $f, PREG_SET_ORDER);
        $this->assertCount(2, $f);
        foreach ($f as [, $name, $params, $ret]) {
            $rm = new \ReflectionMethod(Zstd::class, $name);
            $this->assertSame($ret, (string) $rm->getReturnType());
            $this->assertSame(count(explode(',', $params)), $rm->getNumberOfParameters());
        }
    }
}

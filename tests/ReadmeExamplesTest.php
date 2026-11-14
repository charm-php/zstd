<?php

declare(strict_types=1);

namespace Charm\Zstd\Tests;

use Charm\Zstd\{Decoder, Encoder, Zstd};
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
        $this->expectException(\UnexpectedValueException::class);
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

    public function testStreamingExampleRuns(): void
    {
        $this->assertSame(1, preg_match('/<!-- example: streaming -->\n```php\n(.*?)\n```/s', self::readme(), $m));
        $data = Inputs::text(300000, 2);
        $dir = sys_get_temp_dir() . '/charm-zstd-readme-' . getmypid();
        mkdir($dir);
        $cwd = getcwd();
        try {
            chdir($dir);
            file_put_contents('data.zst', Zstd::compress($data));
            file_put_contents('data.zst.plain', $data);
            ob_start();
            try {
                (static function () use ($m): void {
                    eval($m[1]);
                })();
            } finally {
                $echoed = ob_get_clean();
            }
            $this->assertTrue($data === $echoed, 'the decoding loop echoes the data');
            $this->assertTrue($data === Zstd::decompress((string) file_get_contents('copy.zst')), 'the encoding loop wrote a stream of the data');
        } finally {
            chdir($cwd);
            array_map('unlink', glob("$dir/*") ?: []);
            rmdir($dir);
        }
    }

    public function testStreamingClassListingNamesRealMethods(): void
    {
        foreach ([Decoder::class => 'Decoder', Encoder::class => 'Encoder'] as $class => $short) {
            $this->assertSame(1, preg_match('/final class ' . $short . '\n\{(.*?)\n\}/s', self::readme(), $m), $short);
            preg_match_all('/public function (\w+)\(([^)]*)\)(?:: (\??\w+))?;/', $m[1], $f, PREG_SET_ORDER);
            $this->assertCount(count((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC)), $f);
            foreach ($f as $row) {
                $name = $row[1];
                $params = $row[2];
                $ret = $row[3] ?? '';
                $rm = new \ReflectionMethod($class, $name);
                $this->assertSame($name === '__construct' ? '' : $ret, $name === '__construct' ? '' : (string) $rm->getReturnType());
                $this->assertSame($params === '' ? 0 : count(explode(',', $params)), $rm->getNumberOfParameters());
            }
        }
    }

    public function testErrorsSectionAndNoDecodeException(): void
    {
        $this->assertStringContainsString("\n## Errors\n", self::readme());
        $this->assertFalse(class_exists('Charm\\Zstd\\DecodeException'));
        $this->assertStringNotContainsString('DecodeException', self::readme());
    }
}

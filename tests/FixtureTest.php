<?php

declare(strict_types=1);

namespace Charm\Zstd\Tests;

use Charm\Zstd\Zstd;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The decoder on frames written by the real zstd tool (committed in tests/fixtures; no binary is needed to run this). */
final class FixtureTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function fixtures(): array
    {
        $manifest = json_decode((string) file_get_contents(__DIR__ . '/fixtures/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($manifest as $name => $_) {
            $cases[$name] = [$name];
        }
        return $cases;
    }

    private static function plaintext(string $name): string
    {
        $manifest = json_decode((string) file_get_contents(__DIR__ . '/fixtures/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $plain = '';
        foreach ($manifest[$name]['parts'] as $part) {
            $plain .= Inputs::get($part);
        }
        return $plain;
    }

    #[DataProvider('fixtures')]
    public function testGeneratedPlaintextIsTheOneTheFixtureWasMadeFrom(string $name): void
    {
        $manifest = json_decode((string) file_get_contents(__DIR__ . '/fixtures/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $plain = self::plaintext($name);
        $this->assertSame($manifest[$name]['bytes'], strlen($plain));
        $this->assertSame($manifest[$name]['sha256'], hash('sha256', $plain), 'tests/Inputs.php no longer generates the fixture plaintext');
    }

    #[DataProvider('fixtures')]
    public function testDecodesToThePlaintext(string $name): void
    {
        $zst = file_get_contents(__DIR__ . "/fixtures/$name.zst");
        $this->assertNotFalse($zst);
        $this->assertTrue(self::plaintext($name) === Zstd::decompress($zst), "$name.zst decoded to other bytes");
    }

    public function testFixtureSetCoversTheSettingsThatMatter(): void
    {
        $have = array_keys(self::fixtures());
        foreach (['fast5', 'level1', 'level3', 'level19', 'ultra22', 'long27', 'level3-check', 'level3-nocheck', 'no-compress-literals',
            'no-content-size', 'block4096', 'piped', 'concatenated', 'skippable', 'empty'] as $name) {
            $this->assertContains($name, $have);
        }
    }

    public function testChecksumFlagIsWhatTheNamesSay(): void
    {
        // Frame_Header_Descriptor bit 2 is Content_Checksum_Flag
        $flag = static fn (string $n): int => (ord(file_get_contents(__DIR__ . "/fixtures/$n.zst")[4]) >> 2) & 1;
        $this->assertSame(1, $flag('level3-check'));
        $this->assertSame(0, $flag('level3-nocheck'));
        $this->assertSame(0, $flag('small-level19-nocheck'));
    }

    public function testNoContentSizeFixturesHaveNone(): void
    {
        // Frame_Header_Descriptor: bits 7-6 Frame_Content_Size_flag, bit 5 Single_Segment_flag: no size when both are 0
        foreach (['no-content-size', 'piped', 'piped-check'] as $n) {
            $fhd = ord(file_get_contents(__DIR__ . "/fixtures/$n.zst")[4]);
            $this->assertSame(0, $fhd & 0xE0, "$n.zst declares a content size");
        }
    }

    public function testFixturesOfOurOwnCompressorCoverTheEntropyCoding(): void
    {
        // written by Zstd::compress() and accepted by `zstd -d` when committed (tests/fixtures/generate.php)
        $info = static fn (string $n): array => Frame::firstBlock((string) file_get_contents(__DIR__ . "/fixtures/$n.zst"));
        $text = $info('ours-text');
        $this->assertSame(2, $text['literalsType']);
        $this->assertGreaterThan(0, $text['sequences']);
        $this->assertContains(2, $text['modes'], 'FSE-compressed sequence tables');
        $this->assertSame(0, $info('ours-lits')['sequences'], 'a block of literals only');
        $this->assertSame(2, $info('ours-lits')['literalsType']);
        $this->assertSame(2, $info('ours-skew')['literalsType']);
        $this->assertGreaterThan(0, $info('ours-skew')['literalsFormat'], 'four-stream literals');
    }
}

<?php

declare(strict_types=1);

namespace Charm\Zstd\Tests;

/** Reads the structure of a frame written by Zstd::compress() (single segment, no checksum). */
final class Frame
{
    /**
     * What the first block of a frame made by compress() says: block type, literals type and size format, regenerated size,
     * number of sequences and the three table modes (LL, OF, ML) as 0 predefined / 1 RLE / 2 FSE / 3 repeat.
     *
     * @return array{blockType: int, literalsType: int, literalsFormat: int, regenerated: int, sequences: int, modes: list<int>}
     */
    public static function firstBlock(string $zst): array
    {
        return self::blocks($zst, 1)[0];
    }

    /**
     * The structure of the first $max blocks (see firstBlock()).
     *
     * @return list<array{blockType: int, literalsType: int, literalsFormat: int, regenerated: int, sequences: int, modes: list<int>}>
     */
    public static function blocks(string $zst, int $max = PHP_INT_MAX): array
    {
        $fhd = ord($zst[4]);
        $p = 5 + [0 => 1, 1 => 2, 2 => 4, 3 => 8][$fhd >> 6];
        $all = [];
        while (count($all) < $max && $p + 3 <= strlen($zst)) {
            $bh = ord($zst[$p]) | (ord($zst[$p + 1]) << 8) | (ord($zst[$p + 2]) << 16);
            $size = $bh >> 3;
            $all[] = self::block($zst, $p);
            $p += 3 + (((($bh >> 1) & 3) === 1) ? 1 : $size);
            if ($bh & 1) {
                break;
            }
        }
        return $all;
    }

    /** @return array{blockType: int, literalsType: int, literalsFormat: int, regenerated: int, sequences: int, modes: list<int>} */
    private static function block(string $zst, int $p): array
    {
        $bh = ord($zst[$p]) | (ord($zst[$p + 1]) << 8) | (ord($zst[$p + 2]) << 16);
        $p += 3;
        $type = ($bh >> 1) & 3;
        $info = ['blockType' => $type, 'literalsType' => -1, 'literalsFormat' => -1, 'regenerated' => 0, 'sequences' => -1, 'modes' => []];
        if ($type !== 2) {
            return $info;
        }
        $b = ord($zst[$p]);
        $lt = $b & 3;
        $sf = ($b >> 2) & 3;
        if ($lt <= 1) {
            [$hdr, $regen] = match ($sf) {
                0, 2 => [1, $b >> 3],
                1 => [2, (ord($zst[$p]) | (ord($zst[$p + 1]) << 8)) >> 4],
                default => [3, (ord($zst[$p]) | (ord($zst[$p + 1]) << 8) | (ord($zst[$p + 2]) << 16)) >> 4],
            };
            $comp = $lt === 0 ? $regen : 1;
        } else {
            $v = 0;
            $hdr = [0 => 3, 1 => 3, 2 => 4, 3 => 5][$sf];
            for ($i = 0; $i < $hdr; $i++) {
                $v |= ord($zst[$p + $i]) << (8 * $i);
            }
            $bits = [0 => 10, 1 => 10, 2 => 14, 3 => 18][$sf];
            $regen = ($v >> 4) & ((1 << $bits) - 1);
            $comp = ($v >> (4 + $bits)) & ((1 << $bits) - 1);
        }
        $info['literalsType'] = $lt;
        $info['literalsFormat'] = $sf;
        $info['regenerated'] = $regen;
        $p += $hdr + $comp;
        $n = ord($zst[$p]);
        if ($n === 0) {
            $info['sequences'] = 0;
            return $info;
        }
        if ($n < 128) {
            $p += 1;
        } elseif ($n < 255) {
            $n = (($n - 128) << 8) + ord($zst[$p + 1]);
            $p += 2;
        } else {
            $n = ord($zst[$p + 1]) + (ord($zst[$p + 2]) << 8) + 0x7F00;
            $p += 3;
        }
        $m = ord($zst[$p]);
        $info['sequences'] = $n;
        $info['modes'] = [$m >> 6, ($m >> 4) & 3, ($m >> 2) & 3];
        return $info;
    }
}

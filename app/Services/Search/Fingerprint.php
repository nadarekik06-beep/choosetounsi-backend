<?php

namespace App\Services\Search;

/**
 * Photo fingerprints: 512-dim CLIP vectors stored as float32 BLOBs (2 KB), L2-normalized
 * before packing so the similarity of two photos is a plain dot product.
 * Plus the color maths of the re-ranking (CIELAB distance, no library).
 */
final class Fingerprint
{
    const DIMS = 512;
    const BYTES = self::DIMS * 4;

    /** @param float[] $vector */
    public static function pack(array $vector): string
    {
        return pack('g*', ...self::normalize(array_values($vector)));
    }

    /** @return array<int, float> 1-indexed (as unpack() returns it; dot() works on any matching keys) */
    public static function unpack(string $blob): array
    {
        return unpack('g' . self::DIMS, $blob);
    }

    /** @param float[] $vector */
    public static function normalize(array $vector): array
    {
        $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $vector)));
        return $norm > 0 ? array_map(fn ($x) => $x / $norm, $vector) : $vector;
    }

    public static function dot(array $a, array $b): float
    {
        $s = 0.0;
        foreach ($a as $k => $x) {
            $s += $x * $b[$k];
        }
        return $s;
    }

    /** '#rrggbb' → [L, a, b] (D65), null for anything else. */
    public static function lab(?string $hex): ?array
    {
        if (!$hex || !preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $hex, $m)) {
            return null;
        }
        $lin = function (string $h) {
            $c = hexdec($h) / 255;
            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        [$r, $g, $b] = [$lin($m[1]), $lin($m[2]), $lin($m[3])];
        $xyz = [
            ($r * 0.4124 + $g * 0.3576 + $b * 0.1805) / 0.95047,
            ($r * 0.2126 + $g * 0.7152 + $b * 0.0722),
            ($r * 0.0193 + $g * 0.1192 + $b * 0.9505) / 1.08883,
        ];
        $f = array_map(fn ($t) => $t > 0.008856 ? $t ** (1 / 3) : 7.787 * $t + 16 / 116, $xyz);
        return [round(116 * $f[1] - 16, 2), round(500 * ($f[0] - $f[1]), 2), round(200 * ($f[1] - $f[2]), 2)];
    }

    /** CIE76 ΔE: ~2 barely visible, ~10 clearly different shade, 50+ another color. */
    public static function colorDistance(array $lab1, array $lab2): float
    {
        return sqrt(($lab1[0] - $lab2[0]) ** 2 + ($lab1[1] - $lab2[1]) ** 2 + ($lab1[2] - $lab2[2]) ** 2);
    }
}

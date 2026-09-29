<?php

namespace App\Support;

/**
 * The 24 Tunisian wilayas, stored everywhere by their canonical (French) name —
 * the same values as the storefront's lib/i18n/wilayas.ts (addresses, checkout,
 * seller applications).
 *
 * normalize() maps loose spellings ("Kef", "manouba", "Medenine", "BEJA") onto
 * the canonical name, so values typed or stored before the lists were unified
 * still compare equal.
 */
class Wilayas
{
    const ALL = [
        'Ariana', 'Béja', 'Ben Arous', 'Bizerte', 'Gabès', 'Gafsa',
        'Jendouba', 'Kairouan', 'Kasserine', 'Kébili', 'Le Kef', 'Mahdia',
        'La Manouba', 'Médenine', 'Monastir', 'Nabeul', 'Sfax', 'Sidi Bouzid',
        'Siliana', 'Sousse', 'Tataouine', 'Tozeur', 'Tunis', 'Zaghouan',
    ];

    private static ?array $byKey = null;

    /** Canonical name, or null when the value isn't a wilaya. */
    public static function normalize(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        self::$byKey ??= array_combine(array_map([self::class, 'key'], self::ALL), self::ALL);

        return self::$byKey[self::key($value)] ?? null;
    }

    /** @return string[] canonical names, unknown values dropped, no duplicates */
    public static function normalizeMany(?array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($v) => is_string($v) ? self::normalize($v) : null,
            $values ?? []
        ))));
    }

    /** Case-, accent- and article-insensitive comparison key ("Le Kef" = "kef"). */
    private static function key(string $value): string
    {
        $v = mb_strtolower(trim($value));
        $v = strtr($v, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'û' => 'u', 'ç' => 'c']);
        $v = preg_replace('/^(le|la|el)\s+/', '', $v);
        return preg_replace('/[^a-z]/', '', $v);
    }
}

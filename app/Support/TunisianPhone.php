<?php

namespace App\Support;

/**
 * Tunisian phone numbers, stored as the bare 8 digits ("22123456").
 *
 * Accepts what people actually type — "+216 22 123 456", "0021622-123-456",
 * "22.123.456" — and rejects anything that isn't a mobile number (2x, 4x,
 * 5x, 9x), same rule as the storefront's validateTunisianPhone().
 */
class TunisianPhone
{
    const PATTERN = '/^[2459][0-9]{7}$/';

    /** Strip separators and the +216 / 00216 prefix. Non-strings pass through. */
    public static function normalize(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        $digits = preg_replace('/[\s\-.()]/', '', $value);
        return preg_replace('/^(\+216|00216)/', '', $digits);
    }

    public static function isValid(?string $value): bool
    {
        return $value !== null && preg_match(self::PATTERN, (string) self::normalize($value)) === 1;
    }

    /** "22123456" → "+216 22 123 456" for documents. */
    public static function format(?string $value): string
    {
        $n = (string) self::normalize($value);
        return preg_match('/^\d{8}$/', $n)
            ? '+216 ' . substr($n, 0, 2) . ' ' . substr($n, 2, 3) . ' ' . substr($n, 5)
            : (string) $value;
    }
}

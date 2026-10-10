<?php

namespace App\Support;

/**
 * Money as integer millimes (1 TND = 1000 millimes): no float ever takes part
 * in order money math.
 *
 * Rounding rule (the only one): half-up to the millime, applied once per line
 * (a line's commission, a coupon share, a pack share). Sums of lines are exact.
 *
 * In:  DECIMAL strings from the DB ("12.500"), ints, or request numbers.
 * Out: "12.500" strings for DECIMAL columns, or a float for JSON payloads only.
 */
class Millimes
{
    /** "12.5" / "12.500" / 12.5 / 12 → 12500. Rounds half-up past the 3rd decimal. */
    public static function of($value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (is_int($value)) {
            return $value * 1000;
        }
        // Floats go through their shortest exact decimal text ("0.1", not 0.1000000000000000055…)
        $text = is_float($value) ? self::floatText($value) : trim((string) $value);

        if (!preg_match('/^([+-])?(\d*)(?:\.(\d*))?$/', $text, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new \InvalidArgumentException("Not a money amount: {$text}");
        }
        $negative = ($m[1] ?? '') === '-';
        $whole    = (int) ($m[2] === '' ? '0' : $m[2]);
        $decimals = str_pad($m[3] ?? '', 4, '0');
        $millimes = $whole * 1000 + (int) substr($decimals, 0, 3);
        if ((int) $decimals[3] >= 5) {
            $millimes++;
        }
        return $negative ? -$millimes : $millimes;
    }

    /** 12500 → "12.500" (DECIMAL column / exact text). */
    public static function toDecimal(int $millimes): string
    {
        $sign = $millimes < 0 ? '-' : '';
        $abs  = abs($millimes);
        return $sign . intdiv($abs, 1000) . '.' . str_pad((string) ($abs % 1000), 3, '0', STR_PAD_LEFT);
    }

    /** 12500 → 12.5, for JSON payloads only (never fed back into math). */
    public static function toFloat(int $millimes): float
    {
        return (float) self::toDecimal($millimes);
    }

    /** $amount × $rate% half-up to the millime. $rate has at most 2 decimals ("15", "12.5"). */
    public static function percent(int $amount, $rate): int
    {
        $basisPoints = intdiv(self::of($rate) + 5, 10); // rate × 100, rounded
        return self::divRound($amount * $basisPoints, 10000);
    }

    /** $amount × $num / $den, half-up (away from zero). */
    public static function share(int $amount, int $num, int $den): int
    {
        return $den === 0 ? 0 : self::divRound($amount * $num, $den);
    }

    /** Integer division rounded half-up, away from zero. */
    public static function divRound(int $num, int $den): int
    {
        if ($den < 0) {
            [$num, $den] = [-$num, -$den];
        }
        $q = intdiv(abs($num) * 2 + $den, 2 * $den);
        return $num < 0 ? -$q : $q;
    }

    private static function floatText(float $value): string
    {
        // 17 significant digits round-trip; trim to what PHP prints as shortest repr.
        $text = var_export($value, true);
        if (stripos($text, 'e') !== false) {
            $text = number_format($value, 6, '.', '');
        }
        return $text;
    }
}

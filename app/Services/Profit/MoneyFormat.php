<?php

namespace App\Services\Profit;

use Carbon\CarbonImmutable;

/** DT amounts and month names for notification texts, in the seller's language. */
class MoneyFormat
{
    /** 1234.5 → "1 234,50 DT" (fr) · "1,234.50 DT" (en) · "1.234,50 د.ت" (ar) — same as the dashboard. */
    public static function dt(float $value, ?string $locale = null, int $decimals = 2): string
    {
        $locale = $locale ?? app()->getLocale();
        [$dec, $thousands, $unit] = match ($locale) {
            'en'    => ['.', ',', 'DT'],
            'ar'    => [',', '.', 'د.ت'],
            default => [',', "\u{202F}", 'DT'],
        };
        return number_format($value, $decimals, $dec, $thousands) . ' ' . $unit;
    }

    /** "2026-10" → "octobre 2026" / "October 2026" / "أكتوبر 2026" */
    public static function month(string $ym, ?string $locale = null): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $ym, config('profit.timezone'))
            ->locale($locale ?? app()->getLocale())
            ->translatedFormat('F Y');
    }
}

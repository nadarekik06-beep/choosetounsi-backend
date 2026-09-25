<?php

namespace App\Support;

/**
 * Request-wide switch for translated database content (category / attribute names,
 * product names and descriptions).
 *
 * It is turned on by the SetLocale middleware for storefront requests only. Admin, seller
 * and delivery APIs keep the original values, so edit forms never save a translation over
 * the source text.
 */
class Localization
{
    private static bool $enabled = false;

    public static function enable(bool $on = true): void
    {
        self::$enabled = $on;
    }

    public static function active(): bool
    {
        return self::$enabled;
    }

    public static function locale(): string
    {
        return app()->getLocale();
    }

    /** Run $callback with translations off (e.g. to snapshot source text into an order). */
    public static function original(callable $callback)
    {
        $was = self::$enabled;
        self::$enabled = false;
        try {
            return $callback();
        } finally {
            self::$enabled = $was;
        }
    }

    /**
     * Localize name / description fields of a raw query row (stdClass) for a product.
     * The row must include `translations` (JSON) to be translated; otherwise it is returned as is.
     */
    public static function productRow(object $row, array $fields = ['name', 'description', 'short_description']): object
    {
        if (!self::$enabled || empty($row->translations)) {
            unset($row->translations);
            return $row;
        }

        $all = is_array($row->translations) ? $row->translations : json_decode((string) $row->translations, true);
        $tr  = $all[self::locale()] ?? [];
        foreach ($fields as $f) {
            if (property_exists($row, $f) && !empty($tr[$f])) {
                $row->{$f} = $tr[$f];
            }
        }
        unset($row->translations);

        return $row;
    }

    /** Pick the localized column (name_fr / name_ar) of a raw row, falling back to French then the base value. */
    public static function column(object $row, string $base, ?string $prefix = null): ?string
    {
        $prefix = $prefix ?? $base;
        $value  = $row->{$base} ?? null;
        if (!self::$enabled) {
            return $value;
        }

        $locale = self::locale();
        if ($locale === 'en') {
            return $value;
        }

        return ($row->{"{$prefix}_{$locale}"} ?? null) ?: (($row->{"{$prefix}_fr"} ?? null) ?: $value);
    }
}

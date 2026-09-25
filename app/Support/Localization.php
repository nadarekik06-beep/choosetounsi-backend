<?php

namespace App\Support;

/**
 * Request-wide switch for translated database content (category / attribute names,
 * product names and descriptions).
 *
 * Two switches, set by the SetLocale middleware:
 *  - catalog  (category / subcategory / attribute / option names): storefront and seller
 *    dashboard. These names are picked by id and never saved back from those screens.
 *  - products (product name / descriptions): storefront only. Seller and admin screens edit
 *    products, so they always get the seller's original text.
 * Admin and delivery APIs get neither.
 */
class Localization
{
    private static bool $enabled = false;   // product texts
    private static bool $catalog = false;   // taxonomy names

    public static function enable(bool $products = true, ?bool $catalog = null): void
    {
        self::$enabled = $products;
        self::$catalog = $catalog ?? $products;
    }

    /** Product texts are translated (storefront). */
    public static function active(): bool
    {
        return self::$enabled;
    }

    /** Category / attribute names are translated (storefront + seller dashboard). */
    public static function catalogActive(): bool
    {
        return self::$catalog;
    }

    public static function locale(): string
    {
        return app()->getLocale();
    }

    /** Run $callback with translations off (e.g. to snapshot source text into an order). */
    public static function original(callable $callback)
    {
        [$was, $wasCatalog] = [self::$enabled, self::$catalog];
        self::$enabled = self::$catalog = false;
        try {
            return $callback();
        } finally {
            [self::$enabled, self::$catalog] = [$was, $wasCatalog];
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
        if (!self::$catalog) {
            return $value;
        }

        $locale = self::locale();
        if ($locale === 'en') {
            return $value;
        }

        return ($row->{"{$prefix}_{$locale}"} ?? null) ?: (($row->{"{$prefix}_fr"} ?? null) ?: $value);
    }
}

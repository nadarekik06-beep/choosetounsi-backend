<?php

namespace App\Support;

use App\Models\Category;

/**
 * Season / Occasion tags a seller can put on a product (product_occasions pivot).
 *
 * Single source of truth: the seller form, the admin editor and the storefront
 * read VALUES and CATEGORY_SLUGS through GET /api/product-occasions.
 */
final class Occasions
{
    public const DEFAULT = 'all_season';

    /** value => English label (storefront/seller labels come from next-intl). */
    public const VALUES = [
        'all_season'     => 'All season',
        'summer'         => 'Summer',
        'winter'         => 'Winter',
        'ramadan'        => 'Ramadan',
        'aid'            => 'Aïd',
        'back_to_school' => 'Back to school',
        'wedding_season' => 'Wedding season',
    ];

    /** Categories where sellers pick occasions; every other category is saved as all_season. */
    public const CATEGORY_SLUGS = [
        'fashion-clothing',
        'food-grocery',
        'home-living',
        'kids-baby',
    ];

    public static function keys(): array
    {
        return array_keys(self::VALUES);
    }

    public static function appliesToCategory(?int $categoryId): bool
    {
        if (!$categoryId) return false;
        return in_array(Category::whereKey($categoryId)->value('slug'), self::CATEGORY_SLUGS, true);
    }

    /**
     * Canonical list: known values only, unique, in VALUES order. all_season is
     * exclusive — it is dropped when a specific occasion is also picked, and is
     * the result when nothing is left.
     */
    public static function normalize(array $values): array
    {
        $picked   = array_values(array_intersect(self::keys(), $values));
        $specific = array_values(array_diff($picked, [self::DEFAULT]));
        return $specific ?: [self::DEFAULT];
    }

    /** Value to store for a product in $categoryId, given what was submitted. */
    public static function forCategory(?int $categoryId, ?array $submitted): array
    {
        return self::appliesToCategory($categoryId) ? self::normalize($submitted ?? []) : [self::DEFAULT];
    }
}

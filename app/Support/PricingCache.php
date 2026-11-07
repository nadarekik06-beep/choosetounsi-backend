<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Version number in the keys of the short display caches that hold prices
 * (deals, promo flyers, home feed). Bumped whenever a product or variant price
 * changes or a promotion is created, edited, paused, ended or deleted, so those
 * caches never show an old price, even for their 60-120 s lifetime.
 *
 * Cart, checkout and orders never read these caches: they always price live.
 */
class PricingCache
{
    public const KEY = 'pricing:version';

    public static function version(): int
    {
        return (int) Cache::get(self::KEY, 0);
    }

    /** Never throws: a cache failure must not block a price or promotion save. */
    public static function bump(): void
    {
        try {
            Cache::add(self::KEY, 0, now()->addYears(5));
            Cache::increment(self::KEY);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}

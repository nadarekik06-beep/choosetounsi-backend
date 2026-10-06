<?php

namespace App\Services\GrowthRadar\Detectors;

use Carbon\CarbonImmutable;

/** Prefills for the existing promotion / coupon / boost / product flows. */
trait BuildsActions
{
    /** Discount (days) or flash sale (hours) on these products, starting at $start (local time). */
    protected function promo(string $kind, array $productIds, int $pct, CarbonImmutable $start, int $length): array
    {
        $end = $kind === 'flash_sale' ? $start->addHours(min(72, $length)) : $start->addDays($length);
        return [
            'kind'          => $kind,
            'product_ids'   => array_values(array_map('intval', $productIds)),
            'discount_type' => 'percentage',
            'discount_value'=> $pct,
            'starts_at'     => $start->format('Y-m-d\TH:i'),
            'ends_at'       => $end->format('Y-m-d\TH:i'),
        ];
    }

    protected function coupon(int $productId, int $pct, int $days, int $audience): array
    {
        return [
            'kind' => 'coupon', 'product_ids' => [$productId], 'discount_type' => 'percentage',
            'discount_value' => $pct, 'days' => $days, 'audience' => $audience, 'usage_limit_per_customer' => 1,
        ];
    }

    protected function boost(int $productId): array
    {
        return ['kind' => 'boost', 'product_id' => $productId];
    }

    /** Open the product form; focus = photos | description | price. */
    protected function edit(int $productId, string $focus, ?float $price = null): array
    {
        return array_filter(['kind' => 'edit', 'product_id' => $productId, 'focus' => $focus, 'price' => $price], fn ($v) => $v !== null);
    }

    protected function bundle(int $productId): array
    {
        return ['kind' => 'bundle', 'product_id' => $productId];
    }

    /** Discount percentage that brings $price down to $target, kept within the configured bounds. */
    protected function pctTo(float $price, float $target): int
    {
        $pct = (int) round((1 - $target / max($price, 0.001)) * 100);
        return max((int) config('growth.price.min_discount'), min((int) config('growth.price.max_discount'), $pct));
    }

    protected static function pct(float $rate, int $decimals = 1): float
    {
        return round($rate * 100, $decimals);
    }
}

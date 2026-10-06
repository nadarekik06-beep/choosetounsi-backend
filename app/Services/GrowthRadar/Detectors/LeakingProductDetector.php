<?php

namespace App\Services\GrowthRadar\Detectors;

use App\Services\GrowthRadar\Benchmarks;
use App\Services\GrowthRadar\Card;
use App\Services\GrowthRadar\SellerContext;

/**
 * Many views, few orders. The fix is picked from what the listing lacks, in
 * order: photos, description, price vs the category, else a price test.
 * Benchmark: category (or platform) orders-per-view when above the privacy
 * floor, else a fixed fallback rate — and then the confidence drops a level.
 */
class LeakingProductDetector implements Detector
{
    use BuildsActions;

    public function detect(SellerContext $ctx, Benchmarks $bench): array
    {
        $cfg = config('growth.leaking');
        $cards = [];

        foreach ($ctx->products as $pid => $p) {
            if ($ctx->isClaimed($pid) || $p['promo_running']) continue;
            $views = $p['cur']['views'];
            if ($views < $cfg['min_views']) continue;

            $orders = $p['cur']['orders'];
            $rate = $orders / $views;
            $conv = $bench->conversionFor($p['category_id']);
            $benchRate = $conv['rate'] ?? (float) $cfg['fallback_rate'];
            if ($benchRate <= 0 || $rate >= $benchRate * $cfg['gap_factor']) continue;

            $cartRate = $p['cur']['cart_adds'] / $views;
            $range = $bench->priceRange($p['category_id'], $p['subcategory_id']);
            $price = (float) $p['price'];
            $unit  = $ctx->unitRevenue($p);

            if ($p['images'] < $cfg['min_images']) {
                $focus = 'photos';
                $action = $this->edit($pid, 'photos');
            } elseif ($p['description_len'] < $cfg['short_description']) {
                $focus = 'description';
                $action = $this->edit($pid, 'description');
            } elseif ($range && $price > $range['median'] * 1.1) {
                $focus = 'price';
                $pct = $this->pctTo($price, $range['median']);
                $action = $this->promo('discount', [$pid], $pct, $bench->bestStart(1), (int) config('growth.price.test_days'));
                $unit = $price * (1 - $pct / 100);
            } else {
                $focus = 'price_test';
                $pct = 10;
                $action = $this->promo($ctx->learning->preferredPromo() === 'flash_sale' ? 'flash_sale' : 'discount',
                    [$pid], $pct, $bench->bestStart(1), $ctx->learning->preferredPromo() === 'flash_sale' ? 48 : 7);
                $unit = $unit * 0.9;
            }

            // Closing a quarter to 60 % of the gap to the benchmark, over a month
            $missed = $views * ($benchRate - $rate);
            $confidence = Card::confidenceFromViews($views);
            if (!$conv) $confidence = Card::lower($confidence);

            $card = new Card('leaking_product', "leak:$pid", $pid, $confidence,
                ['product' => $p['name'], 'views' => $views, 'orders' => $orders, 'focus' => $focus,
                 'pct' => $action['discount_value'] ?? null, 'images' => $p['images'],
                 'days' => isset($action['ends_at']) ? max(1, (int) round((strtotime($action['ends_at']) - strtotime($action['starts_at'])) / 86400)) : null,
                 'rate' => self::pct($rate, 1), 'bench_rate' => self::pct($benchRate, 1),
                 'cart_rate' => self::pct($cartRate, 1)],
                ['numbers' => [
                    ['key' => 'views_30d', 'value' => $views],
                    ['key' => 'orders_30d', 'value' => $orders],
                    ['key' => 'conversion', 'value' => self::pct($rate, 2), 'unit' => '%', 'compare' => self::pct($benchRate, 2)],
                 ], 'chart' => ['kind' => 'sparkline'] + $ctx->sparkline($pid)],
                $action,
                $focus === 'description' || $focus === 'photos' ? null : $this->edit($pid, 'description'),
                ['conversion' => $conv['scope'] ?? 'fallback'],
            );
            $kind = $action['kind'] === 'edit' ? 'edit' : $action['kind'];
            $cards[] = $card->impact($missed * 0.25 * $unit, $missed * 0.6 * $unit, $ctx->learning->multiplier($kind));
            $ctx->claim($pid);
        }
        return $cards;
    }
}

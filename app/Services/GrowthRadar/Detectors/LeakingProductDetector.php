<?php

namespace App\Services\GrowthRadar\Detectors;

use App\Services\GrowthRadar\Benchmarks;
use App\Services\GrowthRadar\Card;
use App\Services\GrowthRadar\SellerContext;
use App\Services\VisitorInsights\FunnelDiagnosis;
use App\Services\VisitorInsights\ProductFacts;

/**
 * Many views, few orders. The cause and the fix come from the shared funnel
 * diagnosis (App\Services\VisitorInsights\FunnelDiagnosis), the same rules as
 * Analyse des visiteurs, so the two pages never disagree. The card stays short:
 * the problem, one main action and a link to the full analysis.
 *
 * Benchmark: category (or platform) orders-per-view when above the privacy
 * floor, else a fixed fallback rate — and then the confidence drops a level.
 */
class LeakingProductDetector implements Detector
{
    use BuildsActions;

    public function __construct(private FunnelDiagnosis $diagnosis, private ProductFacts $facts) {}

    public function detect(SellerContext $ctx, Benchmarks $bench): array
    {
        $cfg = config('growth.leaking');
        $candidates = [];
        foreach ($ctx->products as $pid => $p) {
            if ($ctx->isClaimed($pid) || $p['promo_running']) continue;
            $views = $p['cur']['views'];
            if ($views < $cfg['min_views']) continue;
            $conv = $bench->conversionFor($p['category_id']);
            $benchRate = $conv['rate'] ?? (float) $cfg['fallback_rate'];
            if ($benchRate <= 0 || $p['cur']['orders'] / $views >= $benchRate * $cfg['gap_factor']) continue;
            $candidates[$pid] = [$conv, $benchRate];
        }
        if (!$candidates) return [];

        $facts = $this->facts->forSeller($ctx->sellerId, array_keys($candidates));
        $cards = [];
        foreach ($candidates as $pid => [$conv, $benchRate]) {
            $p = $ctx->products[$pid];
            $views = $p['cur']['views'];
            $orders = $p['cur']['orders'];
            $rate = $orders / $views;
            $cartRate = $p['cur']['cart_adds'] / $views;
            $range = $bench->priceRange($p['category_id'], $p['subcategory_id']);
            $f = ($facts[$pid] ?? []) + ['price' => (float) $p['price'], 'listed_days' => $p['listed_days']];
            $resolve = fn (string $metric) => $metric === 'median_price' && $range ? ['value' => $range['median'], 'scope' => $range['scope']] : null;

            // Lost before the cart (few add-to-carts) or between cart and order?
            $atProductPage = $p['cur']['cart_adds'] < (int) config('funnel.min_carts')
                || ($conv && $cartRate < $conv['cart_rate'] * (float) config('funnel.low_factor'));
            [$code] = $atProductPage
                ? $this->diagnosis->productPageCause($f, $resolve)
                : $this->diagnosis->cartCause($f, $resolve);

            $unit = $ctx->unitRevenue($p);
            [$focus, $action] = match ($code) {
                'price_high'      => ['price', $this->promo('discount', [$pid], $pct = $this->pctTo((float) $p['price'], $range['median']),
                                       $bench->bestStart(1), (int) config('growth.price.test_days'))],
                'listing_quality' => match ($f['quality_weakest'] ?? null) {
                    'photos'      => ['photos', $this->edit($pid, 'photos')],
                    'description' => ['description', $this->edit($pid, 'description')],
                    default       => ['listing', $this->edit($pid, $f['quality_weakest'] ?? 'description')],
                },
                'stock_variants'  => ['stock', $this->edit($pid, 'stock')],
                'shipping_cost'   => ['shipping', $this->edit($pid, 'price')],
                default           => ['price_test', $this->promo($ctx->learning->preferredPromo() === 'flash_sale' ? 'flash_sale' : 'discount',
                                       [$pid], 10, $bench->bestStart(1), $ctx->learning->preferredPromo() === 'flash_sale' ? 48 : 7)],
            };
            if ($focus === 'price') $unit = (float) $p['price'] * (1 - $pct / 100);
            if ($focus === 'price_test') $unit *= 0.9;

            // Closing a quarter to 60 % of the gap to the benchmark, over a month
            $missed = $views * ($benchRate - $rate);
            $confidence = Card::confidenceFromViews($views);
            if (!$conv) $confidence = Card::lower($confidence);

            $card = new Card('leaking_product', "leak:$pid", $pid, $confidence,
                ['product' => $p['name'], 'views' => $views, 'orders' => $orders, 'focus' => $focus, 'problem' => $code,
                 'stage' => $atProductPage ? 'product_page' : 'cart',
                 'pct' => $action['discount_value'] ?? null, 'images' => $p['images'], 'score' => $f['quality_score'] ?? null,
                 'days' => isset($action['ends_at']) ? max(1, (int) round((strtotime($action['ends_at']) - strtotime($action['starts_at'])) / 86400)) : null,
                 'rate' => self::pct($rate, 1), 'bench_rate' => self::pct($benchRate, 1),
                 'cart_rate' => self::pct($cartRate, 1)],
                // Short card: the full diagnosis lives in Analyse des visiteurs (insights link)
                ['numbers' => [], 'insights' => true],
                $action,
                null,
                ['conversion' => $conv['scope'] ?? 'fallback'],
            );
            $kind = $action['kind'] === 'edit' ? 'edit' : $action['kind'];
            $cards[] = $card->impact($missed * 0.25 * $unit, $missed * 0.6 * $unit, $ctx->learning->multiplier($kind));
            $ctx->claim($pid);
        }
        return $cards;
    }
}

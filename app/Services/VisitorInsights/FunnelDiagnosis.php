<?php

namespace App\Services\VisitorInsights;

/**
 * Where a product loses its buyers, why, and what to do. Pure rules: no I/O,
 * so the page (Visitor Insights) and Growth Radar's leaking_product card share
 * exactly the same reasoning.
 *
 * Stages, in funnel order (the first leaking one wins):
 *   click         low_visibility (few views vs the category) · low_ctr (seen, rarely clicked)
 *   product_page  views but few add-to-carts → cause, checked in this order:
 *                 price_high · listing_quality · no_reviews · stock_variants · low_add_to_cart
 *   cart          carts but few orders → stock_variants · shipping_cost · cart_abandon
 *   checkout      checkout started, not finished → shipping_cost · checkout_abandon
 *
 * Inputs
 *   $m     impressions, clicks, views, carts, checkouts, orders (ints; impressions /
 *          checkouts null when not tracked over the whole period)
 *   $facts price, delivery_fee, quality_score, quality_weakest, reviews, stock,
 *          variants, variants_out, listed_days
 *   $bench fn(string $metric): ?array{value: float, scope: string}  (FunnelBenchmarks)
 */
class FunnelDiagnosis
{
    public const STAGES = ['click', 'product_page', 'cart', 'checkout'];

    /**
     * @return array{status: string, stage?: string, code?: string, severity?: string,
     *               evidence?: array, actions?: array, lost_revenue?: ?float, rates: array}
     *   status: problem | ok | insufficient
     */
    public function diagnose(array $m, array $facts, callable $bench): array
    {
        $cfg = config('funnel');
        $views = (int) $m['views'];
        $rates = self::rates($m);
        $conv = $bench('conversion');
        $price = (float) ($facts['price'] ?? 0);

        // ── 1. Click: visibility, then CTR ─────────────────────────────────
        $vpp = $bench('views_per_product');
        // A product listed 12 days ago is compared with 12 days' worth of the benchmark, not 30
        $period = (int) ($facts['period_days'] ?? 0);
        $share = $period > 0 ? min(1, ($facts['listed_days'] ?? $period) / $period) : 1;
        $expected = $vpp ? $vpp['value'] * $share : 0;
        if (($facts['listed_days'] ?? 0) >= $cfg['min_listed_days'] && $expected > 0
            && $views < $expected * $cfg['visibility_factor']) {
            $vpp = ['value' => $expected, 'scope' => $vpp['scope']];
            $missing = max(0, $vpp['value'] - $views);
            $rate = $rates['conversion'] !== null && $views >= $cfg['min_views'] ? $rates['conversion'] : ($conv['value'] ?? null);
            return $this->problem('click', 'low_visibility', $views / $vpp['value'],
                [$this->evidence('views', $views, $vpp)],
                [['kind' => 'edit', 'focus' => 'title'], ['kind' => 'boost']],
                $rate !== null ? $missing * $rate * $price : null, $rates);
        }
        $ctr = $bench('ctr');
        if ($m['impressions'] !== null && $m['impressions'] >= $cfg['min_impressions'] && $ctr && $ctr['value'] > 0
            && $rates['ctr'] !== null && $rates['ctr'] < $ctr['value'] * $cfg['low_factor']) {
            $lostClicks = $m['impressions'] * $ctr['value'] - $m['clicks'];
            $rate = $rates['conversion'] !== null && $views >= $cfg['min_views'] ? max($rates['conversion'], $conv['value'] ?? 0) : ($conv['value'] ?? null);
            $priceBench = $bench('median_price');
            $actions = [['kind' => 'edit', 'focus' => 'photos']];
            if ($priceBench && $price > $priceBench['value'] * $cfg['price_high_factor']) {
                $actions[] = ['kind' => 'discount', 'pct' => self::pctTo($price, $priceBench['value'])];
            }
            return $this->problem('click', 'low_ctr', $rates['ctr'] / $ctr['value'],
                [$this->evidence('ctr', $rates['ctr'], $ctr, true)], $actions,
                $rate !== null ? max(0, $lostClicks) * $rate * $price : null, $rates);
        }

        // Everything below needs a real sample of product-page visits
        if ($views < $cfg['min_views']) {
            return ['status' => 'insufficient', 'rates' => $rates];
        }

        // ── 2. Product page: views → cart ──────────────────────────────────
        $v2c = $bench('view_to_cart');
        if ($v2c && $v2c['value'] > 0 && $rates['view_to_cart'] < $v2c['value'] * $cfg['low_factor']) {
            [$code, $evidence, $actions] = $this->productPageCause($facts, $bench);
            array_unshift($evidence, $this->evidence('view_to_cart', $rates['view_to_cart'], $v2c, true));
            return $this->problem('product_page', $code, $rates['view_to_cart'] / $v2c['value'], $evidence, $actions,
                $this->lost($views, $rates['conversion'], $conv, $price), $rates);
        }

        // ── 3. Cart → order ───────────────────────────────────────────────
        $c2o = $bench('cart_to_order');
        if ($m['carts'] >= $cfg['min_carts'] && $c2o && $c2o['value'] > 0
            && $rates['cart_to_order'] < $c2o['value'] * $cfg['low_factor']) {
            // Checkout tracked and enough of them: is the bigger loss cart → checkout, or checkout → order?
            $checkoutStage = $m['checkouts'] !== null && $m['checkouts'] >= $cfg['min_checkouts']
                && $m['orders'] / $m['checkouts'] < 0.5
                && $m['orders'] / $m['checkouts'] < $m['checkouts'] / max(1, $m['carts']);
            [$code, $evidence, $actions] = $this->cartCause($facts, $bench, $checkoutStage);
            array_unshift($evidence, $checkoutStage
                ? ['metric' => 'checkout_to_order', 'value' => round($m['orders'] / $m['checkouts'], 4), 'bench' => null, 'scope' => null, 'rate' => true]
                : $this->evidence('cart_to_order', $rates['cart_to_order'], $c2o, true));
            return $this->problem($checkoutStage ? 'checkout' : 'cart', $code, $rates['cart_to_order'] / $c2o['value'], $evidence, $actions,
                $this->lost($views, $rates['conversion'], $conv, $price), $rates);
        }

        return ['status' => 'ok', 'rates' => $rates];
    }

    /**
     * Why visitors leave the product page without adding to cart.
     * @return array{0: string, 1: array, 2: array} code, evidence, actions
     */
    public function productPageCause(array $facts, callable $bench): array
    {
        $cfg = config('funnel');
        $price = (float) ($facts['price'] ?? 0);
        $median = $bench('median_price');
        if ($median && $median['value'] > 0 && $price > $median['value'] * $cfg['price_high_factor']) {
            return ['price_high', [$this->evidence('price', $price, $median)],
                    [['kind' => 'discount', 'pct' => self::pctTo($price, $median['value'])], ['kind' => 'edit', 'focus' => 'price']]];
        }
        $score = $facts['quality_score'] ?? null;
        if ($score !== null && $score < $cfg['quality_low_score']) {
            $focus = match ($facts['quality_weakest'] ?? null) {
                'photos' => 'photos', 'title' => 'title', 'attributes' => 'attributes', 'stock' => 'stock', default => 'description',
            };
            return ['listing_quality', [['metric' => 'quality_score', 'value' => (int) $score, 'bench' => (int) $cfg['quality_low_score'], 'scope' => 'target', 'weakest' => $facts['quality_weakest'] ?? null]],
                    [['kind' => 'listing_quality'], $focus === 'description' ? ['kind' => 'ai_description'] : ['kind' => 'edit', 'focus' => $focus]]];
        }
        if ((int) ($facts['reviews'] ?? 0) === 0) {
            return ['no_reviews', [['metric' => 'reviews', 'value' => 0, 'bench' => null, 'scope' => null]],
                    [['kind' => 'discount', 'pct' => 10], ['kind' => 'ai_description']]];
        }
        if ((int) ($facts['stock'] ?? 0) <= 0 || (int) ($facts['variants_out'] ?? 0) > 0) {
            return ['stock_variants', [$this->stockEvidence($facts)], [['kind' => 'restock'], ['kind' => 'edit', 'focus' => 'stock']]];
        }
        return ['low_add_to_cart', [], [['kind' => 'ai_description'], ['kind' => 'discount', 'pct' => 10]]];
    }

    /** Why carts (or started checkouts) don't become orders. */
    public function cartCause(array $facts, callable $bench, bool $atCheckout = false): array
    {
        $cfg = config('funnel');
        if ((int) ($facts['stock'] ?? 0) <= 0 || (int) ($facts['variants_out'] ?? 0) > 0) {
            return ['stock_variants', [$this->stockEvidence($facts)], [['kind' => 'restock'], ['kind' => 'edit', 'focus' => 'stock']]];
        }
        $fee = $facts['delivery_fee'] ?? null;
        $feeBench = $bench('median_delivery_fee');
        if ($fee !== null && $feeBench && $feeBench['value'] > 0 && (float) $fee > $feeBench['value'] * $cfg['shipping_high_factor']) {
            return ['shipping_cost', [$this->evidence('delivery_fee', (float) $fee, $feeBench)],
                    [['kind' => 'edit', 'focus' => 'price'], ['kind' => 'discount', 'pct' => 10]]];
        }
        return [$atCheckout ? 'checkout_abandon' : 'cart_abandon', [], [['kind' => 'discount', 'pct' => 10], ['kind' => 'edit', 'focus' => 'price']]];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return array{ctr: ?float, view_to_cart: ?float, cart_to_order: ?float, conversion: ?float} */
    public static function rates(array $m): array
    {
        $div = fn ($a, $b) => $b > 0 ? round(min(1, $a / $b), 4) : null;
        return [
            'ctr'           => $m['impressions'] !== null ? $div($m['clicks'] ?? 0, $m['impressions']) : null,
            'view_to_cart'  => $div($m['carts'], $m['views']),
            'cart_to_order' => $div($m['orders'], $m['carts']),
            'conversion'    => $div($m['orders'], $m['views']),
        ];
    }

    /** Lost revenue = views × (benchmark conversion − product conversion) × price, never negative. */
    private function lost(int $views, ?float $rate, ?array $conv, float $price): ?float
    {
        if (!$conv) return null;
        return max(0.0, $views * ($conv['value'] - (float) $rate) * $price);
    }

    private function problem(string $stage, string $code, float $ratio, array $evidence, array $actions, ?float $lost, array $rates): array
    {
        $cfg = config('funnel');
        return [
            'status'       => 'problem',
            'stage'        => $stage,
            'code'         => $code,
            'severity'     => $ratio < $cfg['high_severity_factor'] ? 'high' : ($ratio < $cfg['low_factor'] ? 'medium' : 'low'),
            'evidence'     => array_values($evidence),
            'actions'      => $actions,
            'lost_revenue' => $lost !== null ? round(max(0, $lost), 1) : null,
            'rates'        => $rates,
        ];
    }

    private function evidence(string $metric, float $value, ?array $bench, bool $rate = false): array
    {
        return ['metric' => $metric, 'value' => round($value, 4), 'bench' => $bench ? round($bench['value'], 4) : null,
                'scope' => $bench['scope'] ?? null, 'rate' => $rate];
    }

    private function stockEvidence(array $facts): array
    {
        return ['metric' => (int) ($facts['stock'] ?? 0) <= 0 ? 'stock' : 'variants_out',
                'value' => (int) ($facts['stock'] ?? 0) <= 0 ? 0 : (int) $facts['variants_out'],
                'bench' => (int) ($facts['variants'] ?? 0) ?: null, 'scope' => null];
    }

    /** Discount that brings $price to $target, 5–30 %. */
    public static function pctTo(float $price, float $target): int
    {
        $pct = (int) round((1 - $target / max($price, 0.001)) * 100);
        return max(5, min(30, $pct));
    }
}

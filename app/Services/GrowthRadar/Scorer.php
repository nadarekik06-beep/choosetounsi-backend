<?php

namespace App\Services\GrowthRadar;

/**
 * Weekly Growth Score (0–100) = mean of the sub-scores we can compute:
 *
 *  pricing    — share of products inside their category's price range (p25×0.9 … p75×1.1)
 *  visibility — views per product vs the platform median (median = 50, twice = 100)
 *  conversion — orders per view vs the platform rate (same scale); needs 30 views
 *  stock      — share of products neither out of stock, idle, nor about to run out
 *
 * A sub-score is null when its data isn't there yet; `unlocks` says what would
 * unlock more (shown in the empty state, never filled with made-up numbers).
 */
class Scorer
{
    public function score(SellerContext $ctx, Benchmarks $bench): array
    {
        $live = count($ctx->products);
        $t = $ctx->totals();
        $inputs = ['products' => $live, 'views' => $t['views'], 'orders' => $t['orders']];

        // Pricing
        $withRange = $inRange = 0;
        foreach ($ctx->products as $p) {
            $r = $bench->priceRange($p['category_id'], $p['subcategory_id']);
            if (!$r) continue;
            $withRange++;
            if ($p['price'] >= $r['p25'] * 0.9 && $p['price'] <= $r['p75'] * 1.1) $inRange++;
        }
        $pricing = $withRange ? (int) round(100 * $inRange / $withRange) : null;
        $inputs['priced_products'] = $withRange;

        // Visibility
        $vpp = $bench->viewsPerProduct();
        $visibility = null;
        if ($live && $vpp) {
            $own = $t['views'] / $live;
            $visibility = self::scale($own / max(1.0, $vpp['median']));
            $inputs['views_per_product'] = round($own, 1);
            $inputs['platform_views_per_product'] = round($vpp['median'], 1);
        }

        // Conversion
        $conv = $bench->conversion(null);
        $conversion = null;
        if ($conv && $t['views'] >= 30 && $conv['rate'] > 0) {
            $rate = $t['orders'] / $t['views'];
            $conversion = self::scale($rate / $conv['rate']);
            $inputs['conversion'] = round($rate * 100, 2);
            $inputs['platform_conversion'] = round($conv['rate'] * 100, 2);
        }

        // Stock health
        $stock = null;
        if ($live) {
            $deadDays = (int) config('growth.dead_stock.days');
            $healthy = 0;
            $issues = ['out' => 0, 'idle' => 0, 'low_cover' => 0];
            foreach ($ctx->products as $p) {
                $daily = $p['cur']['units'] / config('growth.window_days');
                $idle = $p['listed_days'] >= $deadDays && $p['stock'] >= config('growth.dead_stock.min_stock')
                    && (!$p['last_sale'] || $ctx->today->diffInDays(\Carbon\CarbonImmutable::parse($p['last_sale'], $ctx->today->tz)) >= $deadDays);
                if ($p['stock'] <= 0) $issues['out']++;
                elseif ($idle) $issues['idle']++;
                elseif ($daily > 0 && $p['stock'] / $daily < 7) $issues['low_cover']++;
                else $healthy++;
            }
            $stock = (int) round(100 * $healthy / $live);
            $inputs['stock_issues'] = $issues;
        }

        $subs = compact('pricing', 'visibility', 'conversion', 'stock');
        $known = array_filter($subs, fn ($v) => $v !== null);
        $score = $known ? (int) round(array_sum($known) / count($known)) : null;

        return $subs + ['score' => $score, 'inputs' => $inputs + ['unlocks' => $this->unlocks($live, $t, $pricing, $vpp, $conv)]];
    }

    /** ratio 1 → 50, ratio 2 or more → 100 */
    private static function scale(float $ratio): int
    {
        return (int) max(0, min(100, round(50 * $ratio)));
    }

    /** What would give this seller more (or better) insights, most useful first. */
    private function unlocks(int $live, array $t, ?int $pricing, ?array $vpp, ?array $conv): array
    {
        $out = [];
        if ($live < 3) $out[] = ['key' => 'add_products', 'count' => 3 - $live];
        if ($t['views'] < 50) $out[] = ['key' => 'more_views', 'count' => 50 - $t['views']];
        if ($t['orders'] === 0 && $live > 0) $out[] = ['key' => 'first_sale'];
        if ($pricing === null && $live > 0) $out[] = ['key' => 'market_data'];
        if (!$vpp || !$conv) $out[] = ['key' => 'platform_data'];
        return $out;
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

/**
 * AutoPromotionService  -- Phase 3
 *
 * Detects trending products that are NOT yet sponsored and
 * generates plain-language promotion suggestions with estimated
 * revenue boost (in TND, not percentages).
 *
 * Algorithm:
 *   1. Find trending products (7-day velocity > 1.5x 30-day avg)
 *   2. Filter out already-sponsored ones
 *   3. For each, estimate boost = velocity_multiplier * 0.35 * weekly_revenue
 *      (35% visibility uplift from sponsorship, conservative estimate)
 *   4. Generate plain-language rationale
 *   5. Sort by estimated boost desc
 *
 * Returns max 5 suggestions.
 */
class AutoPromotionService
{
    /** Sponsorship visibility uplift factor (conservative) */
    private const SPONSORSHIP_UPLIFT = 0.35;

    /** Min weekly revenue to be worth suggesting */
    private const MIN_WEEKLY_REVENUE = 50.0;

    /** Max suggestions to return */
    private const MAX_SUGGESTIONS = 5;

    public function suggest(int $sellerId): array
    {
        $sellerCol = $this->sellerCol();
        $totalExpr = $this->totalExpr();
        $now       = Carbon::now();

        // ── 30-day daily average per product ─────────────────────────────
        $thirtyDayAvg = DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where("p.{$sellerCol}", $sellerId)
            ->whereNull('p.deleted_at')
            ->whereIn('o.status', ['completed', 'delivered'])
            ->where('o.created_at', '>=', $now->copy()->subDays(30))
            ->selectRaw("oi.product_id, SUM(oi.quantity) / 30.0 as daily_avg")
            ->groupBy('oi.product_id')
            ->get()
            ->keyBy('product_id');

        // ── 7-day totals ──────────────────────────────────────────────────
        $sevenDay = DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where("p.{$sellerCol}", $sellerId)
            ->whereNull('p.deleted_at')
            ->whereIn('o.status', ['completed', 'delivered'])
            ->where('o.created_at', '>=', $now->copy()->subDays(7))
            ->selectRaw("oi.product_id, SUM(oi.quantity) as units, SUM({$totalExpr}) as revenue")
            ->groupBy('oi.product_id')
            ->get()
            ->keyBy('product_id');

        // ── Products with images and sponsorship status ───────────────────
        $products = DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('product_images as pi', function ($j) {
                $j->on('pi.product_id', '=', 'p.id')
                  ->where('pi.is_primary', true)
                  ->whereNull('pi.variant_id');
            })
            ->where("p.{$sellerCol}", $sellerId)
            ->whereNull('p.deleted_at')
            ->where('p.is_approved', true)
            ->selectRaw("p.id, p.name, p.price, p.is_sponsored, c.name as category_name, MIN(pi.image_path) as image_path")
            ->groupBy('p.id', 'p.name', 'p.price', 'p.is_sponsored', 'c.name')
            ->get()
            ->keyBy('id');

        // ── Find trending products ────────────────────────────────────────
        $suggestions = [];

        foreach ($sevenDay as $productId => $sales) {
            $avg   = $thirtyDayAvg[$productId]->daily_avg ?? 0;
            $daily = $sales->units / 7.0;

            if ($avg <= 0 || $daily < $avg * 1.5) continue;

            $product = $products[$productId] ?? null;
            if (!$product) continue;

            $weeklyRevenue = (float) $sales->revenue;
            if ($weeklyRevenue < self::MIN_WEEKLY_REVENUE) continue;

            $multiplier  = round($daily / $avg, 2);
            $signal      = $multiplier >= 2.5 ? 'hot' : ($multiplier >= 1.8 ? 'rising' : 'warm');
            $boostEst    = (int) round($weeklyRevenue * self::SPONSORSHIP_UPLIFT);
            $alreadySpon = (bool) $product->is_sponsored;

            $suggestions[] = [
                'product_id'          => (int) $productId,
                'product_name'        => $product->name,
                'category'            => $product->category_name ?? 'Uncategorized',
                'image_url'           => $product->image_path
                    ? url(Storage::url($product->image_path))
                    : null,
                'trend_signal'        => $signal,
                'velocity_label'      => $this->velocityLabel($multiplier),
                'velocity_multiplier' => $multiplier,
                'seven_day_revenue'   => number_format($weeklyRevenue, 3),
                'estimated_boost_tnd' => $boostEst,
                'already_sponsored'   => $alreadySpon,
                'rationale'           => $this->buildRationale($product->name, $signal, $multiplier, $weeklyRevenue),
                'boost_explanation'   => $this->buildBoostExplanation($boostEst, $signal),
            ];
        }

        // Sort: non-sponsored first, then by estimated boost desc
        usort($suggestions, function ($a, $b) {
            if ($a['already_sponsored'] !== $b['already_sponsored']) {
                return $a['already_sponsored'] ? 1 : -1;
            }
            return $b['estimated_boost_tnd'] <=> $a['estimated_boost_tnd'];
        });

        return array_slice($suggestions, 0, self::MAX_SUGGESTIONS);
    }

    // ── Language helpers ──────────────────────────────────────────────────────

    private function velocityLabel(float $m): string
    {
        if ($m >= 3) return 'Selling ' . round($m) . 'x faster than usual';
        if ($m >= 2) return 'Selling twice as fast as usual';
        return 'Selling 1.5x faster than usual';
    }

    private function buildRationale(string $name, string $signal, float $m, float $revenue): string
    {
        $roundedRevenue = number_format($revenue, 0);

        if ($signal === 'hot') {
            return "{$name} is your hottest product right now — selling " . round($m) . "x faster than usual "
                 . "and generating {$roundedRevenue} TND this week. "
                 . "Sponsoring it will push it in front of even more buyers who are ready to purchase.";
        }

        if ($signal === 'rising') {
            return "{$name} is picking up strong momentum this week. "
                 . "It has already generated {$roundedRevenue} TND and demand is accelerating. "
                 . "Sponsor it now to ride the wave while it is hot.";
        }

        return "{$name} is outperforming its usual pace this week ({$roundedRevenue} TND earned). "
             . "A sponsorship boost would help it reach buyers who haven't discovered it yet.";
    }

    private function buildBoostExplanation(int $boostTnd, string $signal): string
    {
        if ($signal === 'hot') {
            return "Based on your current sales pace, sponsorship typically adds around {$boostTnd} TND "
                 . "in extra revenue per week by placing your product at the top of search results and category pages.";
        }

        return "Sponsorship usually delivers a 35% visibility increase. "
             . "At your current pace, that translates to roughly {$boostTnd} TND in additional revenue this week.";
    }

    // ── Column detection ──────────────────────────────────────────────────────

    private function sellerCol(): string
    {
        static $col = null;
        if ($col) return $col;
        $cols = array_map(fn($c) => $c->Field, DB::select('SHOW COLUMNS FROM products'));
        return $col = in_array('seller_id', $cols) ? 'seller_id' : 'user_id';
    }

    private function totalExpr(): string
    {
        $cols  = array_map(fn($c) => $c->Field, DB::select('SHOW COLUMNS FROM order_items'));
        $parts = [];
        if (in_array('total', $cols))                                            $parts[] = 'oi.total';
        if (in_array('unit_price', $cols) && in_array('quantity', $cols))       $parts[] = 'oi.unit_price * oi.quantity';
        elseif (in_array('price', $cols) && in_array('quantity', $cols))        $parts[] = 'oi.price * oi.quantity';
        $parts[] = '0';
        return 'COALESCE(' . implode(', ', $parts) . ')';
    }
}
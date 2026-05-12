<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

/**
 * FunnelInsightService
 *
 * Detects products with HIGH VIEWS but LOW CONVERSIONS.
 * "Lots of people looking but nobody buying."
 *
 * Algorithm:
 *   - Look at all seller products active + approved
 *   - For products with views > MIN_VIEWS (default 50)
 *   - Calculate conversion_rate = (units_sold_30d / views) * 100
 *   - Flag products where conversion_rate < THRESHOLD (default 1.0%)
 *   - Sort by revenue opportunity (views * avg_price * benchmark_conversion)
 *
 * Output: plain-language diagnosis + fix suggestion per product.
 * No technical terms exposed to seller.
 */
class FunnelInsightService
{
    /** Minimum views to be included in analysis */
    private const MIN_VIEWS = 50;

    /** Conversion rate below this % = flagged */
    private const CONVERSION_THRESHOLD = 1.0;

    /** What a "normal" conversion rate looks like (for opportunity calc) */
    private const BENCHMARK_CONVERSION = 2.5;

    /** Max products to return */
    private const MAX_RESULTS = 10;

    public function analyze(int $sellerId): array
    {
        $sellerCol = $this->sellerCol();
        $totalExpr = $this->totalExpr();
        $now       = Carbon::now();

        // ── 1. Products with enough views to analyze ──────────────────────
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
            ->where('p.is_active', true)
            ->where('p.views', '>=', self::MIN_VIEWS)
            ->selectRaw("p.id, p.name, p.price, p.views, c.name as category_name, MIN(pi.image_path) as image_path")
            ->groupBy('p.id', 'p.name', 'p.price', 'p.views', 'c.name')
            ->get()
            ->keyBy('id');

        if ($products->isEmpty()) {
            return [];
        }

        // ── 2. Units sold in last 30 days per product ─────────────────────
        $unitsSold = DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where("p.{$sellerCol}", $sellerId)
            ->whereIn('oi.product_id', $products->keys()->toArray())
            ->whereIn('o.status', ['completed', 'delivered'])
            ->where('o.created_at', '>=', $now->copy()->subDays(30))
            ->selectRaw("oi.product_id, SUM(oi.quantity) as units, SUM({$totalExpr}) as revenue")
            ->groupBy('oi.product_id')
            ->get()
            ->keyBy('product_id');

        // ── 3. Compute conversion + flag low performers ───────────────────
        $results = [];

        foreach ($products as $product) {
            $sold    = (int) ($unitsSold[$product->id]->units ?? 0);
            $views   = (int) $product->views;
            $convPct = $views > 0 ? round(($sold / $views) * 100, 2) : 0;

            // Only flag products below threshold
            if ($convPct >= self::CONVERSION_THRESHOLD) {
                continue;
            }

            // Revenue opportunity: what they'd earn at benchmark conversion
            $priceFloat = (float) $product->price;
            $opportunity = round(
                ($views * (self::BENCHMARK_CONVERSION / 100) - $sold) * $priceFloat,
                0
            );

            // Determine the most likely fix
            $fixType = $this->diagnoseFix($product, $convPct, $sold, $views);

            $results[] = [
                'product_id'       => (int) $product->id,
                'product_name'     => $product->name,
                'category'         => $product->category_name ?? 'Uncategorized',
                'image_url'        => $product->image_path
                    ? url(Storage::url($product->image_path))
                    : null,
                'views'            => $views,
                'units_sold'       => $sold,
                'conversion_pct'   => $convPct,
                'opportunity_tnd'  => number_format(max(0, $opportunity), 0),
                'diagnosis'        => $this->buildDiagnosis($convPct, $views, $sold),
                'fix_suggestion'   => $fixType['suggestion'],
                'fix_type'         => $fixType['type'],
                'fix_action_label' => $fixType['action_label'],
                'fix_action_href'  => $fixType['action_href'],
            ];
        }

        // Sort by opportunity desc
        usort($results, fn($a, $b) =>
            (float) str_replace(',', '', $b['opportunity_tnd']) <=> (float) str_replace(',', '', $a['opportunity_tnd'])
        );

        return array_slice($results, 0, self::MAX_RESULTS);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function diagnoseFix(object $product, float $convPct, int $sold, int $views): array
    {
        // Check image count
        $imageCount = DB::table('product_images')
            ->where('product_id', $product->id)
            ->whereNull('variant_id')
            ->count();

        if ($imageCount < 2) {
            return [
                'type'         => 'image',
                'suggestion'   => 'Add more photos. Products with 3+ images sell significantly better.',
                'action_label' => 'Add Photos',
                'action_href'  => "/seller/products/{$product->id}",
            ];
        }

        // Check description length
        $descLength = DB::table('products')
            ->where('id', $product->id)
            ->value('description');
        $descLength = strlen($descLength ?? '');

        if ($descLength < 80) {
            return [
                'type'         => 'description',
                'suggestion'   => 'Write a better description. Tell buyers what makes this product special.',
                'action_label' => 'Improve Description',
                'action_href'  => "/seller/products/{$product->id}",
            ];
        }

        // Check price vs category average
        $categoryAvgPrice = DB::table('products as p')
            ->join('categories as c', 'c.id', '=', 'p.category_id')
            ->where('p.category_id', DB::table('products')->where('id', $product->id)->value('category_id'))
            ->where('p.is_approved', true)
            ->where('p.is_active', true)
            ->whereNull('p.deleted_at')
            ->avg('p.price');

        if ($categoryAvgPrice && (float)$product->price > $categoryAvgPrice * 1.3) {
            return [
                'type'         => 'price',
                'suggestion'   => 'Your price may be higher than similar products. Try a small discount to see if it converts better.',
                'action_label' => 'Add a Discount',
                'action_href'  => '/seller/promotions',
            ];
        }

        // Default: promote to increase trust
        return [
            'type'         => 'promote',
            'suggestion'   => 'Sponsor this product to increase visibility and build trust with new buyers.',
            'action_label' => 'Promote It',
            'action_href'  => '/seller/promote',
        ];
    }

    private function buildDiagnosis(float $convPct, int $views, int $sold): string
    {
        if ($sold === 0) {
            return "{$views} people visited this product in the last 30 days but nobody bought. "
                 . "Something is stopping them — usually the photos, the description, or the price.";
        }

        $ratio = (int) round($views / max($sold, 1));
        return "About {$views} people visited this product recently, but only {$sold} bought. "
             . "That means roughly 1 in every {$ratio} visitors makes a purchase — "
             . "improving the listing could convert far more of them.";
    }

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
        if (in_array('total', $cols))                                              $parts[] = 'oi.total';
        if (in_array('unit_price', $cols) && in_array('quantity', $cols))         $parts[] = 'oi.unit_price * oi.quantity';
        elseif (in_array('price', $cols) && in_array('quantity', $cols))          $parts[] = 'oi.price * oi.quantity';
        $parts[] = '0';
        return 'COALESCE(' . implode(', ', $parts) . ')';
    }
}
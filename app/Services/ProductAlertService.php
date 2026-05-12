<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * ProductAlertService
 *
 * Detects underperforming products and generates actionable seller alerts.
 *
 * DESIGN RULES:
 *  1. No alert fires before MIN_LISTING_AGE_DAYS — product needs time
 *  2. All thresholds are configurable via config/alerts.php or env
 *  3. Returns structured metadata — frontend renders, backend decides
 *  4. Single batch query per page load — no N+1
 *  5. Debug mode: pass ?alert_debug=1 to lower all thresholds to 0
 */
class ProductAlertService
{
    // ── Thresholds (configurable) ─────────────────────────────────────────

    private int   $minListingAgeDays;    // Product must be listed for at least X days
    private int   $windowDays;           // Sales analysis window
    private int   $lowSalesThreshold;   // Fewer than X units sold in window = low
    private int   $highStockThreshold;  // More than X units remaining = high stock risk
    private float $stockSalesRatio;     // stock / sales > X = problematic ratio
    private int   $lowViewsThreshold;   // Fewer than X views = visibility problem

    public function __construct(bool $debugMode = false)
    {
        if ($debugMode) {
            // Debug mode: all thresholds set to 0 — every product triggers alerts
            $this->minListingAgeDays  = 0;
            $this->windowDays         = 0;
            $this->lowSalesThreshold  = 999999;
            $this->highStockThreshold = 0;
            $this->stockSalesRatio    = 0.0;
            $this->lowViewsThreshold  = 999999;
        } else {
            $this->minListingAgeDays  = config('alerts.min_listing_age_days',  30);
            $this->windowDays         = config('alerts.window_days',           30);
            $this->lowSalesThreshold  = config('alerts.low_sales_threshold',    3);
            $this->highStockThreshold = config('alerts.high_stock_threshold',  15);
            $this->stockSalesRatio    = config('alerts.stock_sales_ratio',    10.0);
            $this->lowViewsThreshold  = config('alerts.low_views_threshold',   50);
        }
    }

    /**
     * Analyze a page of products and attach alert metadata to each one.
     *
     * @param  \Illuminate\Support\Collection $products  Product rows from index()
     * @param  int   $sellerId
     * @param  array $itemColNames  order_items column list (already computed in controller)
     * @return \Illuminate\Support\Collection  Same products with alert_data appended
     */
    public function attachAlerts(
        \Illuminate\Support\Collection $products,
        int $sellerId,
        array $itemColNames
    ): \Illuminate\Support\Collection {

        if ($products->isEmpty()) return $products;

        $productIds = $products->pluck('id')->toArray();

        // ── Single batch query: sales in window per product ───────────────
        $salesMap = $this->fetchSalesInWindow($productIds, $itemColNames);

        $cutoffDate = Carbon::now()->subDays($this->minListingAgeDays);

        return $products->map(function ($product) use ($salesMap, $cutoffDate) {
            $alertData = $this->computeAlert($product, $salesMap, $cutoffDate);
            $product->alert_data = $alertData;
            return $product;
        });
    }

    /**
     * Fetch sales counts for multiple products in one query.
     *
     * @return array<int, int>  product_id => units_sold_in_window
     */
    private function fetchSalesInWindow(array $productIds, array $itemColNames): array
    {
        $windowStart = Carbon::now()->subDays($this->windowDays);

        // Build quantity expression
        $qtyExpr = in_array('quantity', $itemColNames) ? 'oi.quantity' : '1';

        try {
            $rows = DB::table('order_items as oi')
                ->join('orders as o', 'o.id', '=', 'oi.order_id')
                ->whereIn('oi.product_id', $productIds)
                ->whereIn('o.status', ['completed', 'delivered'])
                ->where('o.created_at', '>=', $windowStart)
                ->select('oi.product_id', DB::raw("SUM({$qtyExpr}) as units_sold"))
                ->groupBy('oi.product_id')
                ->get();

            $map = [];
            foreach ($rows as $row) {
                $map[(int)$row->product_id] = (int)$row->units_sold;
            }
            return $map;

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[ProductAlertService] Sales query failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Compute alert level and metadata for a single product.
     */
    private function computeAlert($product, array $salesMap, Carbon $cutoffDate): array
    {
        $productId   = $product->id;
        $stock       = (int)($product->variant_stock ?? $product->stock ?? 0);
        $views       = (int)($product->views ?? 0);
        $createdAt   = $product->created_at
            ? Carbon::parse($product->created_at)
            : Carbon::now();

        $unitsSold   = $salesMap[$productId] ?? 0;
        $listingAge  = $createdAt->diffInDays(Carbon::now());

        // ── Guard: product too new ────────────────────────────────────────
        if ($listingAge < $this->minListingAgeDays) {
            return $this->noAlert();
        }

        // ── Guard: out of stock — different alert, not "low sales" ────────
        if ($stock === 0) {
            return $this->noAlert(); // out-of-stock already handled by Restock button
        }

        // ── Compute individual signals ────────────────────────────────────
        $reasons         = [];
        $suggestedActions = [];
        $alertScore      = 0;

        // Signal 1: Zero sales in window (CRITICAL)
        $hasZeroSales = $unitsSold === 0;
        if ($hasZeroSales) {
            $reasons[]   = [
                'key'     => 'zero_sales',
                'label'   => 'No sales in ' . $this->windowDays . ' days',
                'detail'  => 'This product has had 0 sales in the last ' . $this->windowDays . ' days despite being listed for ' . $listingAge . ' days.',
                'severity'=> 'critical',
            ];
            $alertScore += 40;
        }

        // Signal 2: Low sales (but not zero)
        $hasLowSales = !$hasZeroSales && $unitsSold < $this->lowSalesThreshold;
        if ($hasLowSales) {
            $reasons[] = [
                'key'     => 'low_sales',
                'label'   => 'Low sales detected',
                'detail'  => "Only {$unitsSold} unit(s) sold in the last {$this->windowDays} days.",
                'severity'=> 'warning',
            ];
            $alertScore += 25;
        }

        // Signal 3: High stock with poor sales ratio
        $stockRatio    = $unitsSold > 0 ? ($stock / $unitsSold) : PHP_FLOAT_MAX;
        $hasHighStock  = $stock > $this->highStockThreshold;
        $hasBadRatio   = $stockRatio > $this->stockSalesRatio;

        if ($hasHighStock && $hasBadRatio) {
            $reasons[] = [
                'key'     => 'high_stock_risk',
                'label'   => 'High stock risk',
                'detail'  => "You have {$stock} units but only {$unitsSold} sold recently. Stock is tying up capital.",
                'severity'=> 'warning',
            ];
            $alertScore += 20;
        }

        // Signal 4: Visibility problem
        $hasLowViews = $views < $this->lowViewsThreshold;
        if ($hasLowViews && ($hasZeroSales || $hasLowSales)) {
            $reasons[] = [
                'key'     => 'low_visibility',
                'label'   => 'Low visibility',
                'detail'  => "Only {$views} views. Your product may not be appearing in search results.",
                'severity'=> 'info',
            ];
            $alertScore += 15;
        }

        // ── No alert if no signals triggered ─────────────────────────────
        if (empty($reasons)) {
            return $this->noAlert();
        }

        // ── Determine alert level ─────────────────────────────────────────
        $alertLevel = match(true) {
            $alertScore >= 40 => 'critical',
            $alertScore >= 20 => 'warning',
            default           => 'info',
        };

        // ── Build suggested actions ───────────────────────────────────────
        if ($hasZeroSales || $hasLowSales) {
            $suggestedActions[] = [
                'key'   => 'flash_sale',
                'label' => 'Create Flash Sale',
                'icon'  => 'zap',
                'href'  => '/seller/promotions?create=flash_sale',
                'color' => '#f59e0b',
            ];
            $suggestedActions[] = [
                'key'   => 'optimize_price',
                'label' => 'Optimize Price',
                'icon'  => 'dollar-sign',
                'href'  => '/seller/ai-tools?tab=price&product_id=' . $productId . '&autorun=1',
                'color' => '#db142e',
                'requires_plan' => 'red', // gated for Red/Black
            ];
        }

        if ($hasHighStock) {
            $suggestedActions[] = [
                'key'   => 'bundle',
                'label' => 'Bundle Product',
                'icon'  => 'package',
                'href'  => '/seller/packs',
                'color' => '#8b5cf6',
                'requires_plan' => 'red',
            ];
        }

        if ($hasLowViews) {
            $suggestedActions[] = [
                'key'   => 'promote',
                'label' => 'Promote Product',
                'icon'  => 'trending-up',
                'href'  => '/seller/promote',
                'color' => '#10b981',
            ];
        }

        $suggestedActions[] = [
            'key'   => 'description',
            'label' => 'Improve Listing',
            'icon'  => 'file-text',
            'href'  => '/seller/ai-tools?tab=description&product_id=' . $productId,
            'color' => '#3b82f6',
            'requires_plan' => 'red',
        ];

        return [
            'has_alert'        => true,
            'alert_level'      => $alertLevel,  // 'critical' | 'warning' | 'info'
            'alert_score'      => $alertScore,
            'listing_age_days' => $listingAge,
            'units_sold_window'=> $unitsSold,
            'window_days'      => $this->windowDays,
            'stock'            => $stock,
            'views'            => $views,
            'reasons'          => $reasons,
            'suggested_actions'=> $suggestedActions,
        ];
    }

    private function noAlert(): array
    {
        return ['has_alert' => false];
    }
}
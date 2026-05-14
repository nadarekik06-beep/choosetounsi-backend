<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ForecastEngine
 *
 * Core deterministic forecasting engine for ChooseTounsi.
 *
 * Approach:
 *   1. Collect real order history (6–18 months)
 *   2. Decompose into: trend + seasonality + event signals
 *   3. Project forward N months
 *   4. Blend with category peer data when own history is sparse
 *
 * This is a "Prophet-lite" implementation in pure PHP:
 *   - Linear trend extrapolation from last 3 months
 *   - Multiplicative seasonality (Tunisia-calibrated)
 *   - Event overlay (from product_event_signals table)
 *   - Category peer smoothing for new products
 *
 * When FastAPI + Prophet is enabled (AI_SERVICE_URL configured),
 * this service delegates to it instead — the interface is identical.
 */
class ForecastEngine
{
    // ── Tunisia seasonality multipliers (month 1–12) ────────────────────
    // Calibrated from observed Tunisian e-commerce patterns.
    // Index 0 = January, 11 = December
    private const TUNISIA_MONTHLY_INDEX = [
        1  => 1.05,  // January  — post-Eid recovery, soldes
        2  => 0.98,  // February — quiet
        3  => 1.35,  // March    — Ramadan (varies by year; boosted by events)
        4  => 1.20,  // April    — Post-Ramadan, spring
        5  => 1.08,  // May      — steady
        6  => 1.12,  // June     — early summer, school finish
        7  => 1.18,  // July     — summer peak, tourism
        8  => 1.25,  // August   — summer + back-to-school prep
        9  => 1.22,  // September— school start, highest conversion
        10 => 1.05,  // October  — post-back-to-school plateau
        11 => 0.95,  // November — quiet (before December)
        12 => 1.15,  // December — New Year, soldes
    ];

    // Confidence thresholds
    private const CONF_HIGH   = 20; // ≥20 real orders
    private const CONF_MEDIUM = 5;  // ≥5 real orders
    private const FORECAST_MONTHS = 6;

    /**
     * Generate a 6-month forward forecast for a product.
     *
     * @param  int   $productId   Must belong to $sellerId
     * @param  int   $sellerId
     * @param  array $options     ['months' => 6, 'include_events' => true]
     * @return array              The full forecast payload
     */
    public function forecast(int $productId, int $sellerId, array $options = []): array
    {
        $months        = $options['months']         ?? self::FORECAST_MONTHS;
        $includeEvents = $options['include_events'] ?? true;

        // ── 1. Product info ────────────────────────────────────────────────
        $sellerCol = $this->sellerCol();
        $totalExpr = $this->totalExpr();

        $product = DB::table('products as p')
            ->leftJoin('categories as c',    'c.id', '=', 'p.category_id')
            ->leftJoin('subcategories as s', 's.id', '=', 'p.subcategory_id')
            ->where("p.{$sellerCol}", $sellerId)
            ->where('p.id', $productId)
            ->whereNull('p.deleted_at')
            ->selectRaw("
                p.id, p.name, p.price, p.stock, p.views,
                p.season, p.category_id, p.subcategory_id,
                c.name as category_name, c.slug as category_slug,
                s.name as subcategory_name
            ")
            ->first();

        if (!$product) {
            return ['error' => 'Product not found'];
        }

        // ── 2. Historical monthly sales (18 months) ────────────────────────
        $history = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('oi.product_id', $productId)
            ->whereIn('o.status', ['completed', 'delivered'])
            ->where('o.created_at', '>=', Carbon::now()->subMonths(18))
            ->selectRaw("
                DATE_FORMAT(o.created_at, '%Y-%m') as month,
                YEAR(o.created_at)                 as yr,
                MONTH(o.created_at)                as mo,
                SUM(oi.quantity)                   as units,
                SUM({$totalExpr})                  as revenue,
                COUNT(DISTINCT oi.order_id)        as orders
            ")
            ->groupBy('month', 'yr', 'mo')
            ->orderBy('month')
            ->get();

        $totalHistoryOrders = $history->sum('orders');
        $avgMonthly         = $history->isNotEmpty()
            ? round($history->avg('units'), 2)
            : 0.0;

        // ── 3. Trend component ─────────────────────────────────────────────
        // Linear regression on last 6 months to get a monthly trend slope
        $trendSlope = $this->computeTrendSlope($history->take(-6)->values());

        // ── 4. Category peer baseline (used when own history < 6 months) ──
        $peerBaseline = $this->computeCategoryPeerBaseline(
            (int) $product->category_id,
            $productId,
            $totalExpr
        );

        // If we have less than 3 months of own data, blend with peers
        $ownMonths  = $history->count();
        $baseUnit   = $avgMonthly;
        $blendNote  = '';

        if ($ownMonths < 3 && $peerBaseline['avg'] > 0) {
            $ownWeight   = max(0.1, $ownMonths / 6);
            $peerWeight  = 1.0 - $ownWeight;
            $baseUnit    = round($avgMonthly * $ownWeight + $peerBaseline['avg'] * $peerWeight, 2);
            $blendNote   = "Blended with {$peerBaseline['count']} category peers (own data: {$ownMonths} months).";
        }

        if ($baseUnit <= 0) {
            $baseUnit = max(1.0, $peerBaseline['avg']);
        }

        // ── 5. Load upcoming event signals ────────────────────────────────
        $upcomingEvents = $includeEvents ? $this->loadUpcomingEvents($product->category_slug) : collect();

        // ── 6. Project forward N months ────────────────────────────────────
        $projections = [];
        $startMonth  = Carbon::now()->startOfMonth()->addMonth(); // start from next month

        for ($i = 0; $i < $months; $i++) {
            $targetDate  = $startMonth->copy()->addMonths($i);
            $monthNum    = (int) $targetDate->format('n');
            $yearMonth   = $targetDate->format('Y-m');
            $label       = $targetDate->format('M Y');

            // a) Base = smooth of own avg + trend slope extrapolation
            $trendedBase = max(0, $baseUnit + $trendSlope * ($i + 1));

            // b) Tunisia monthly seasonality index
            $seasonIdx = self::TUNISIA_MONTHLY_INDEX[$monthNum] ?? 1.0;

            // c) Event overlay
            $eventBoost  = 1.0;
            $eventName   = null;
            $matchedEvent = $upcomingEvents->first(function ($ev) use ($targetDate) {
                $start = Carbon::parse($ev->starts_at);
                $end   = Carbon::parse($ev->ends_at);
                return $targetDate->between($start->startOfMonth(), $end->endOfMonth());
            });
            if ($matchedEvent) {
                $eventBoost = (float) $matchedEvent->boost_score;
                $eventName  = $matchedEvent->event_name;
            }

            // d) Compute predicted units
            $predicted = max(1, (int) round($trendedBase * $seasonIdx * $eventBoost));

            // e) Revenue estimate
            $priceRef        = (float) $product->price;
            $predictedRevenue = round($predicted * $priceRef, 3);

            // f) Confidence per month (degrades further out)
            $monthConfidence = $this->monthConfidence($totalHistoryOrders, $i);

            $projections[] = [
                'month'             => $yearMonth,
                'label'             => $label,
                'predicted_units'   => $predicted,
                'predicted_revenue' => $predictedRevenue,
                'trend_base'        => round($trendedBase, 2),
                'seasonality_idx'   => $seasonIdx,
                'event_boost'       => $eventBoost,
                'event_name'        => $eventName,
                'confidence'        => $monthConfidence,
            ];
        }

        // ── 7. Summary metrics ─────────────────────────────────────────────
        $totalPredictedUnits   = array_sum(array_column($projections, 'predicted_units'));
        $totalPredictedRevenue = array_sum(array_column($projections, 'predicted_revenue'));
        $peakMonth             = collect($projections)->sortByDesc('predicted_units')->first();
        $lowestMonth           = collect($projections)->sortBy('predicted_units')->first();

        $overallTrend = 'stable';
        if ($trendSlope > 0.5)  $overallTrend = 'up';
        if ($trendSlope < -0.5) $overallTrend = 'down';

        $globalConfidence = $this->globalConfidence($totalHistoryOrders);

        // ── 8. Demand score (0–100) ────────────────────────────────────────
        $demandScore = $this->computeDemandScore(
            avgMonthly:     $avgMonthly,
            trendSlope:     $trendSlope,
            views:          (int) $product->views,
            totalOrders:    $totalHistoryOrders,
            peerAvg:        $peerBaseline['avg']
        );

        // ── 9. Stock recommendation ────────────────────────────────────────
        $next3MonthsUnits = array_sum(
            array_column(array_slice($projections, 0, 3), 'predicted_units')
        );
        $stockRec = (int) round($next3MonthsUnits * 1.30); // 30% safety buffer

        return [
            // Core result
            'product_id'          => $productId,
            'product_name'        => $product->name,
            'category_name'       => $product->category_name,
            'subcategory_name'    => $product->subcategory_name,
            'current_price'       => (float) $product->price,
            'current_stock'       => (int) $product->stock,

            // Projections array (6 months)
            'projections'         => $projections,

            // Summary
            'total_predicted_units'   => $totalPredictedUnits,
            'total_predicted_revenue' => round($totalPredictedRevenue, 3),
            'peak_month'              => $peakMonth,
            'lowest_month'            => $lowestMonth,
            'overall_trend'           => $overallTrend,
            'trend_slope'             => round($trendSlope, 3),

            // Scores
            'demand_score'        => $demandScore,
            'confidence'          => $globalConfidence,
            'confidence_label'    => $this->confidenceLabel($totalHistoryOrders),
            'data_points'         => $totalHistoryOrders,

            // History (for chart baseline)
            'history'             => $history->map(fn($r) => [
                'month'   => $r->month,
                'label'   => Carbon::createFromFormat('Y-m', $r->month)->format('M Y'),
                'units'   => (int) $r->units,
                'revenue' => round((float) $r->revenue, 3),
                'orders'  => (int) $r->orders,
            ])->values()->toArray(),

            // Recommendations
            'stock_recommendation_3m' => $stockRec,
            'blend_note'              => $blendNote,

            // Metadata
            'computed_at'         => now()->toIso8601String(),
            'computed_by'         => 'laravel_forecast_engine_v1',
            'forecast_months'     => $months,
        ];
    }

    /**
     * Compute regional demand breakdown from orders.wilaya
     */
    public function regionalDemand(int $productId, int $sellerId): array
    {
        $rows = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('oi.product_id', $productId)
            ->whereIn('o.status', ['completed', 'delivered'])
            ->whereNotNull('o.wilaya')
            ->where('o.wilaya', '!=', '')
            ->selectRaw("
                o.wilaya,
                COUNT(DISTINCT oi.order_id) as total_orders,
                SUM(oi.quantity)            as total_units,
                SUM(oi.total)               as total_revenue
            ")
            ->groupBy('o.wilaya')
            ->orderByDesc('total_units')
            ->get();

        if ($rows->isEmpty()) {
            return ['has_data' => false, 'regions' => [], 'top_region' => null];
        }

        $maxUnits = $rows->max('total_units');

        $regions = $rows->map(function ($r) use ($maxUnits) {
            return [
                'wilaya'        => $r->wilaya,
                'total_orders'  => (int)   $r->total_orders,
                'total_units'   => (int)   $r->total_units,
                'total_revenue' => round((float) $r->total_revenue, 3),
                'demand_index'  => $maxUnits > 0
                    ? round(($r->total_units / $maxUnits) * 100, 1)
                    : 0,
            ];
        })->values()->toArray();

        // Merge with full Tunisia governorate list (ensures all 24 appear on map)
        $allGovernorates = $this->allTunisianGovernorates();
        $regionByName    = collect($regions)->keyBy('wilaya');

        $fullMap = array_map(function ($gov) use ($regionByName) {
            $existing = $regionByName->get($gov['name']);
            return $existing ?? [
                'wilaya'        => $gov['name'],
                'total_orders'  => 0,
                'total_units'   => 0,
                'total_revenue' => 0.0,
                'demand_index'  => 0.0,
                'lat'           => $gov['lat'],
                'lng'           => $gov['lng'],
            ];
        }, $allGovernorates);

        // Re-attach lat/lng to real data rows
        foreach ($fullMap as &$item) {
            $govMeta = collect($allGovernorates)->firstWhere('name', $item['wilaya']);
            $item['lat'] = $govMeta['lat'] ?? null;
            $item['lng'] = $govMeta['lng'] ?? null;
        }
        unset($item);

        return [
            'has_data'   => true,
            'regions'    => $fullMap,
            'top_region' => $regions[0] ?? null,
        ];
    }

    // ── Private helpers ────────────────────────────────────────────────────

    /**
     * Compute linear trend slope (units per month) from last N months.
     * Positive = growing, negative = declining.
     */
    private function computeTrendSlope(\Illuminate\Support\Collection $months): float
    {
        $n = $months->count();
        if ($n < 2) return 0.0;

        $x = range(1, $n);
        $y = $months->pluck('units')->map(fn($v) => (float)$v)->toArray();

        $xMean = array_sum($x) / $n;
        $yMean = array_sum($y) / $n;

        $numerator   = 0.0;
        $denominator = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $numerator   += ($x[$i] - $xMean) * ($y[$i] - $yMean);
            $denominator += ($x[$i] - $xMean) ** 2;
        }

        return $denominator > 0 ? round($numerator / $denominator, 3) : 0.0;
    }

    /**
     * Category peer baseline — average monthly units for similar products.
     */
    private function computeCategoryPeerBaseline(
        int $categoryId,
        int $excludeProductId,
        string $totalExpr
    ): array {
        if (!$categoryId) return ['avg' => 0.0, 'count' => 0];

        $peers = DB::table('order_items as oi')
            ->join('orders as o',   'o.id',  '=', 'oi.order_id')
            ->join('products as p', 'p.id',  '=', 'oi.product_id')
            ->where('p.category_id', $categoryId)
            ->where('p.id', '!=', $excludeProductId)
            ->where('p.is_approved', true)
            ->where('p.is_active', true)
            ->whereNull('p.deleted_at')
            ->whereIn('o.status', ['completed', 'delivered'])
            ->where('o.created_at', '>=', Carbon::now()->subMonths(6))
            ->selectRaw("
                p.id as product_id,
                SUM(oi.quantity) as total_units,
                COUNT(DISTINCT DATE_FORMAT(o.created_at, '%Y-%m')) as active_months
            ")
            ->groupBy('p.id')
            ->having('active_months', '>=', 1)
            ->get();

        if ($peers->isEmpty()) return ['avg' => 0.0, 'count' => 0];

        $monthlyAvgs = $peers->map(fn($p) => (float)$p->total_units / max(1, (int)$p->active_months));
        return [
            'avg'   => round($monthlyAvgs->average(), 2),
            'count' => $peers->count(),
        ];
    }

    /**
     * Load upcoming events for the given category slug.
     */
    private function loadUpcomingEvents(?string $categorySlug): \Illuminate\Support\Collection
    {
        $query = DB::table('product_event_signals')
            ->where('is_active', true)
            ->where('ends_at', '>=', Carbon::now()->format('Y-m-d'))
            ->where('starts_at', '<=', Carbon::now()->addMonths(self::FORECAST_MONTHS)->format('Y-m-d'))
            ->orderBy('starts_at');

        if ($categorySlug) {
            // Include events that affect all categories OR this specific one
            $query->where(function ($q) use ($categorySlug) {
                $q->whereNull('affected_categories')
                  ->orWhereJsonContains('affected_categories', $categorySlug);
            });
        }

        return $query->get();
    }

    /**
     * Compute demand score 0–100 from multiple signals.
     */
    private function computeDemandScore(
        float $avgMonthly,
        float $trendSlope,
        int   $views,
        int   $totalOrders,
        float $peerAvg
    ): int {
        // Component 1: absolute volume (0–35)
        $volumeScore = min(35, ($avgMonthly / max(1, $peerAvg > 0 ? $peerAvg : 10)) * 35);

        // Component 2: trend direction (0–25)
        $trendScore = match(true) {
            $trendSlope > 2  => 25,
            $trendSlope > 0  => 15,
            $trendSlope == 0 => 10,
            $trendSlope > -2 => 5,
            default          => 0,
        };

        // Component 3: conversion signal (0–20)
        $conversionScore = 0;
        if ($views > 0 && $totalOrders > 0) {
            $convRate = ($totalOrders / $views) * 100;
            $conversionScore = min(20, $convRate * 4);
        }

        // Component 4: data richness bonus (0–20)
        $richnessScore = min(20, ($totalOrders / self::CONF_HIGH) * 20);

        $raw = $volumeScore + $trendScore + $conversionScore + $richnessScore;
        return (int) min(100, round($raw));
    }

    private function monthConfidence(int $historyOrders, int $monthsAhead): string
    {
        // Confidence degrades with months ahead
        $degraded = $historyOrders / (($monthsAhead + 1) * 1.5);
        return match(true) {
            $degraded >= self::CONF_HIGH   => 'high',
            $degraded >= self::CONF_MEDIUM => 'medium',
            default                        => 'low',
        };
    }

    private function globalConfidence(int $orders): int
    {
        // 0–100 score
        return (int) min(100, round(($orders / self::CONF_HIGH) * 75 + 25));
    }

    private function confidenceLabel(int $orders): string
    {
        return match(true) {
            $orders >= self::CONF_HIGH   => 'high',
            $orders >= self::CONF_MEDIUM => 'medium',
            default                      => 'low',
        };
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
        if (in_array('total',      $cols)) $parts[] = 'oi.total';
        if (in_array('unit_price', $cols) && in_array('quantity', $cols))
            $parts[] = 'oi.unit_price * oi.quantity';
        elseif (in_array('price', $cols) && in_array('quantity', $cols))
            $parts[] = 'oi.price * oi.quantity';
        $parts[] = '0';
        return 'COALESCE(' . implode(', ', $parts) . ')';
    }

    /**
     * All 24 Tunisian governorates with coordinates for the ECharts heatmap.
     */
    private function allTunisianGovernorates(): array
    {
        return [
            ['name' => 'Tunis',        'lat' => 36.8190, 'lng' => 10.1658],
            ['name' => 'Ariana',       'lat' => 36.8625, 'lng' => 10.1956],
            ['name' => 'Ben Arous',    'lat' => 36.7533, 'lng' => 10.2282],
            ['name' => 'Manouba',      'lat' => 36.8088, 'lng' => 10.0984],
            ['name' => 'Nabeul',       'lat' => 36.4561, 'lng' => 10.7376],
            ['name' => 'Zaghouan',     'lat' => 36.4025, 'lng' => 10.1433],
            ['name' => 'Bizerte',      'lat' => 37.2746, 'lng' => 9.8738],
            ['name' => 'Béja',         'lat' => 36.7333, 'lng' => 9.1833],
            ['name' => 'Jendouba',     'lat' => 36.5011, 'lng' => 8.7803],
            ['name' => 'Le Kef',       'lat' => 36.1820, 'lng' => 8.7141],
            ['name' => 'Siliana',      'lat' => 36.0847, 'lng' => 9.3708],
            ['name' => 'Sousse',       'lat' => 35.8256, 'lng' => 10.6369],
            ['name' => 'Monastir',     'lat' => 35.7643, 'lng' => 10.8113],
            ['name' => 'Mahdia',       'lat' => 35.5047, 'lng' => 11.0622],
            ['name' => 'Sfax',         'lat' => 34.7406, 'lng' => 10.7603],
            ['name' => 'Kairouan',     'lat' => 35.6781, 'lng' => 10.0963],
            ['name' => 'Kasserine',    'lat' => 35.1667, 'lng' => 8.8333],
            ['name' => 'Sidi Bouzid',  'lat' => 35.0382, 'lng' => 9.4849],
            ['name' => 'Gabès',        'lat' => 33.8814, 'lng' => 10.0982],
            ['name' => 'Medenine',     'lat' => 33.3547, 'lng' => 10.5053],
            ['name' => 'Tataouine',    'lat' => 32.9211, 'lng' => 10.4508],
            ['name' => 'Gafsa',        'lat' => 34.4250, 'lng' => 8.7842],
            ['name' => 'Tozeur',       'lat' => 33.9197, 'lng' => 8.1335],
            ['name' => 'Kébili',       'lat' => 33.7050, 'lng' => 8.9715],
        ];
    }
}
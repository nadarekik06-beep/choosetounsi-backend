<?php
// app/Http/Controllers/Api/Seller/SellerAnalyticsController.php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * SellerAnalyticsController
 *
 * Advanced analytics only available for Red Pepper (plan = 'red') and
 * Black Pepper (plan = 'black') sellers.
 *
 * Routes (add to api.php inside the seller prefix group):
 *   GET /api/seller/analytics/overview
 *   GET /api/seller/analytics/products
 *   GET /api/seller/analytics/customers
 *   GET /api/seller/analytics/heatmap
 *
 * Plan gate: checked via SellerPlanMiddleware before reaching the controller.
 */
class SellerAnalyticsController extends Controller
{
    /**
     * Detect the seller_id column name used in the products table.
     * Your codebase already uses this pattern in SellerDashboardController.
     */
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
        if (in_array('subtotal',   $cols)) $parts[] = 'oi.subtotal';
        if (in_array('line_total', $cols)) $parts[] = 'oi.line_total';
        if (in_array('unit_price', $cols) && in_array('quantity', $cols))
            $parts[] = 'oi.unit_price * oi.quantity';
        elseif (in_array('price', $cols) && in_array('quantity', $cols))
            $parts[] = 'oi.price * oi.quantity';
        $parts[] = '0';
        return 'COALESCE(' . implode(', ', $parts) . ')';
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/seller/analytics/overview
    // Deeper KPIs: conversion proxies, avg order value, repeat rate, etc.
    // ─────────────────────────────────────────────────────────────────────
    public function overview(Request $request)
    {
        $sellerId  = auth()->id();
        $sellerCol = $this->sellerCol();
        $totalExpr = $this->totalExpr();
        $now       = Carbon::now();

        // ── Period helpers ─────────────────────────────────────────────────
        $periods = [
            'this_week'   => [$now->copy()->startOfWeek(),    $now->copy()->endOfWeek()],
            'last_week'   => [$now->copy()->subWeek()->startOfWeek(), $now->copy()->subWeek()->endOfWeek()],
            'this_month'  => [$now->copy()->startOfMonth(),   $now->copy()->endOfMonth()],
            'last_month'  => [$now->copy()->subMonth()->startOfMonth(), $now->copy()->subMonth()->endOfMonth()],
            'this_quarter'=> [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()],
        ];

        // ── Seller order IDs ───────────────────────────────────────────────
        $sellerOrderIds = DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->where("p.{$sellerCol}", $sellerId)
            ->whereNull('p.deleted_at')
            ->distinct()
            ->pluck('oi.order_id');

        $base = DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->join('orders as o',   'o.id', '=', 'oi.order_id')
            ->where("p.{$sellerCol}", $sellerId)
            ->whereNull('p.deleted_at');

        // ── Average order value ────────────────────────────────────────────
        $avgOrderValue = $sellerOrderIds->isNotEmpty()
            ? DB::table('order_items as oi')
                ->join('products as p', 'p.id', '=', 'oi.product_id')
                ->join('orders as o',   'o.id', '=', 'oi.order_id')
                ->where("p.{$sellerCol}", $sellerId)
                ->whereNull('p.deleted_at')
                ->whereIn('o.id', $sellerOrderIds)
                ->whereIn('o.status', ['completed', 'delivered'])
                ->select(DB::raw("AVG({$totalExpr}) as avg_val"))
                ->value('avg_val')
            : 0;

        // ── Repeat customer rate ───────────────────────────────────────────
        $repeatData = ['repeat_customers' => 0, 'total_unique_customers' => 0];
        if ($sellerOrderIds->isNotEmpty()) {
            $customerOrders = DB::table('orders as o')
                ->whereIn('o.id', $sellerOrderIds)
                ->selectRaw('o.user_id, COUNT(*) as order_count')
                ->groupBy('o.user_id')
                ->get();

            $total  = $customerOrders->count();
            $repeat = $customerOrders->where('order_count', '>', 1)->count();
            $repeatData = [
                'repeat_customers'       => $repeat,
                'total_unique_customers' => $total,
                'repeat_rate_pct'        => $total > 0 ? round(($repeat / $total) * 100, 1) : 0,
            ];
        }

        // ── Weekly breakdown (last 8 weeks) ────────────────────────────────
        $weeklyRevenue = collect();
        for ($i = 7; $i >= 0; $i--) {
            $start = $now->copy()->subWeeks($i)->startOfWeek();
            $end   = $now->copy()->subWeeks($i)->endOfWeek();
            $label = $start->format('d M');

            if ($sellerOrderIds->isEmpty()) {
                $weeklyRevenue->push(['week' => $label, 'revenue' => 0, 'orders' => 0]);
                continue;
            }

            $row = (clone $base)
                ->whereBetween('o.created_at', [$start, $end])
                ->whereIn('o.status', ['completed', 'delivered'])
                ->selectRaw("SUM({$totalExpr}) as revenue, COUNT(DISTINCT oi.order_id) as orders")
                ->first();

            $weeklyRevenue->push([
                'week'    => $label,
                'revenue' => round((float)($row->revenue ?? 0), 3),
                'orders'  => (int)($row->orders ?? 0),
            ]);
        }

        // ── Daily breakdown (last 30 days) ─────────────────────────────────
        $dailyRevenue = collect();
        for ($i = 29; $i >= 0; $i--) {
            $day = $now->copy()->subDays($i);
            if ($sellerOrderIds->isEmpty()) {
                $dailyRevenue->push(['day' => $day->format('d/m'), 'revenue' => 0, 'orders' => 0]);
                continue;
            }
            $row = (clone $base)
                ->whereDate('o.created_at', $day->toDateString())
                ->whereIn('o.status', ['completed', 'delivered'])
                ->selectRaw("SUM({$totalExpr}) as revenue, COUNT(DISTINCT oi.order_id) as orders")
                ->first();
            $dailyRevenue->push([
                'day'     => $day->format('d/m'),
                'revenue' => round((float)($row->revenue ?? 0), 3),
                'orders'  => (int)($row->orders ?? 0),
            ]);
        }

        // ── Revenue by payment method ──────────────────────────────────────
        $revenueByPayment = [];
        if ($sellerOrderIds->isNotEmpty()) {
            $revenueByPayment = (clone $base)
                ->whereIn('o.status', ['completed', 'delivered'])
                ->selectRaw("o.payment_method, SUM({$totalExpr}) as revenue, COUNT(DISTINCT oi.order_id) as orders")
                ->groupBy('o.payment_method')
                ->get()
                ->map(fn($r) => [
                    'method'  => $r->payment_method ?? 'unknown',
                    'revenue' => round((float)$r->revenue, 3),
                    'orders'  => (int)$r->orders,
                ]);
        }

        // ── Period comparisons ─────────────────────────────────────────────
        $periodStats = [];
        foreach (['this_week', 'last_week', 'this_month', 'last_month'] as $key) {
            [$start, $end] = $periods[$key];
            $rev = $sellerOrderIds->isNotEmpty()
                ? (clone $base)
                    ->whereBetween('o.created_at', [$start, $end])
                    ->whereIn('o.status', ['completed', 'delivered'])
                    ->sum(DB::raw($totalExpr))
                : 0;
            $periodStats[$key] = round((float)$rev, 3);
        }

        $weekGrowth = $periodStats['last_week'] > 0
            ? round((($periodStats['this_week'] - $periodStats['last_week']) / $periodStats['last_week']) * 100, 1)
            : ($periodStats['this_week'] > 0 ? 100.0 : 0.0);

        $monthGrowth = $periodStats['last_month'] > 0
            ? round((($periodStats['this_month'] - $periodStats['last_month']) / $periodStats['last_month']) * 100, 1)
            : ($periodStats['this_month'] > 0 ? 100.0 : 0.0);

        return response()->json([
            'success' => true,
            'data' => [
                'avg_order_value'    => round((float)$avgOrderValue, 3),
                'repeat_customers'   => $repeatData,
                'period_stats'       => array_merge($periodStats, [
                    'week_growth'  => $weekGrowth,
                    'month_growth' => $monthGrowth,
                ]),
                'revenue_by_payment' => $revenueByPayment,
                'charts' => [
                    'weekly_revenue' => $weeklyRevenue,
                    'daily_revenue'  => $dailyRevenue,
                ],
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/seller/analytics/products
    // Per-product performance: revenue, conversion proxy, returns, etc.
    // ─────────────────────────────────────────────────────────────────────
    public function products(Request $request)
    {
        $sellerId  = auth()->id();
        $sellerCol = $this->sellerCol();
        $totalExpr = $this->totalExpr();

        $products = DB::table('products as p')
            ->leftJoin('order_items as oi', 'oi.product_id', '=', 'p.id')
            ->leftJoin('orders as o', function ($join) {
                $join->on('o.id', '=', 'oi.order_id')
                     ->whereIn('o.status', ['completed', 'delivered']);
            })
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where("p.{$sellerCol}", $sellerId)
            ->whereNull('p.deleted_at')
            ->selectRaw("
                p.id,
                p.name,
                p.slug,
                p.price,
                p.stock,
                p.views,
                p.is_active,
                p.is_approved,
                c.name as category_name,
                COALESCE(SUM({$totalExpr}), 0)           as total_revenue,
                COALESCE(SUM(oi.quantity), 0)            as total_units_sold,
                COUNT(DISTINCT oi.order_id)              as total_orders,
                COALESCE(AVG({$totalExpr}), 0)           as avg_order_value
            ")
            ->groupBy('p.id', 'p.name', 'p.slug', 'p.price', 'p.stock',
                      'p.views', 'p.is_active', 'p.is_approved', 'c.name')
            ->orderByDesc('total_revenue')
            ->limit(50)
            ->get()
            ->map(fn($r) => [
                'id'             => $r->id,
                'name'           => $r->name,
                'slug'           => $r->slug,
                'price'          => (float)$r->price,
                'stock'          => (int)$r->stock,
                'views'          => (int)$r->views,
                'is_active'      => (bool)$r->is_active,
                'is_approved'    => (bool)$r->is_approved,
                'category_name'  => $r->category_name,
                'total_revenue'  => round((float)$r->total_revenue, 3),
                'total_units'    => (int)$r->total_units_sold,
                'total_orders'   => (int)$r->total_orders,
                'avg_order_val'  => round((float)$r->avg_order_value, 3),
                // Views-to-purchase conversion proxy
                'conversion_rate'=> $r->views > 0 ? round(($r->total_orders / $r->views) * 100, 2) : 0,
                // Revenue per view proxy
                'revenue_per_view'=> $r->views > 0 ? round($r->total_revenue / $r->views, 3) : 0,
            ]);

        // ── Category breakdown ─────────────────────────────────────────────
        $byCategoryRaw = $products->groupBy('category_name');
        $byCategory = $byCategoryRaw->map(fn($items, $cat) => [
            'category'      => $cat ?? 'Uncategorized',
            'total_revenue' => round($items->sum('total_revenue'), 3),
            'total_units'   => $items->sum('total_units'),
            'product_count' => $items->count(),
        ])->values()->sortByDesc('total_revenue')->values();

        // ── Stock health ───────────────────────────────────────────────────
        $stockHealth = [
            'healthy'   => $products->where('stock', '>', 10)->count(),
            'low_stock' => $products->whereBetween('stock', [1, 10])->count(),
            'out'       => $products->where('stock', 0)->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'products'    => $products->values(),
                'by_category' => $byCategory,
                'stock_health'=> $stockHealth,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/seller/analytics/customers
    // RFM-lite: Recency, Frequency, Monetary value per customer.
    // ─────────────────────────────────────────────────────────────────────
    public function customers(Request $request)
    {
        $sellerId  = auth()->id();
        $sellerCol = $this->sellerCol();
        $totalExpr = $this->totalExpr();

        $sellerOrderIds = DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->where("p.{$sellerCol}", $sellerId)
            ->whereNull('p.deleted_at')
            ->distinct()
            ->pluck('oi.order_id');

        if ($sellerOrderIds->isEmpty()) {
            return response()->json(['success' => true, 'data' => ['customers' => [], 'segments' => []]]);
        }

        $customers = DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->join('users as u', 'u.id', '=', 'o.user_id')
            ->where("p.{$sellerCol}", $sellerId)
            ->whereNull('p.deleted_at')
            ->whereIn('o.status', ['completed', 'delivered'])
            ->selectRaw("
                u.id,
                u.name,
                u.email,
                COUNT(DISTINCT oi.order_id)     as order_count,
                SUM({$totalExpr})               as total_spent,
                AVG({$totalExpr})               as avg_order_value,
                MAX(o.created_at)               as last_order_at,
                MIN(o.created_at)               as first_order_at
            ")
            ->groupBy('u.id', 'u.name', 'u.email')
            ->orderByDesc('total_spent')
            ->limit(100)
            ->get()
            ->map(function ($c) {
                $daysSinceLast = Carbon::parse($c->last_order_at)->diffInDays(now());
                // Simple RFM score (1-5 each dimension, normalized)
                $recency   = $daysSinceLast <= 7 ? 5 : ($daysSinceLast <= 30 ? 4 : ($daysSinceLast <= 60 ? 3 : ($daysSinceLast <= 90 ? 2 : 1)));
                $frequency = min(5, (int)$c->order_count);
                $monetary  = min(5, max(1, (int)ceil((float)$c->total_spent / 50)));
                $rfm       = round(($recency + $frequency + $monetary) / 3, 1);

                $segment = match(true) {
                    $rfm >= 4.5 => 'Champion',
                    $rfm >= 3.5 => 'Loyal',
                    $rfm >= 2.5 => 'Regular',
                    $rfm >= 1.5 => 'At Risk',
                    default     => 'Lost',
                };

                return [
                    'id'              => $c->id,
                    'name'            => $c->name,
                    'email'           => $c->email,
                    'order_count'     => (int)$c->order_count,
                    'total_spent'     => round((float)$c->total_spent, 3),
                    'avg_order_value' => round((float)$c->avg_order_value, 3),
                    'last_order_at'   => $c->last_order_at,
                    'first_order_at'  => $c->first_order_at,
                    'days_since_last' => $daysSinceLast,
                    'rfm_score'       => $rfm,
                    'segment'         => $segment,
                ];
            });

        // ── Segment counts ─────────────────────────────────────────────────
        $segments = $customers->groupBy('segment')
            ->map(fn($g, $seg) => ['segment' => $seg, 'count' => $g->count(), 'revenue' => round($g->sum('total_spent'), 3)])
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'customers' => $customers->values(),
                'segments'  => $segments,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/seller/analytics/heatmap
    // Order counts by hour-of-day + day-of-week (real DB data)
    // ─────────────────────────────────────────────────────────────────────
    public function heatmap(Request $request)
    {
        $sellerId  = auth()->id();
        $sellerCol = $this->sellerCol();

        $sellerOrderIds = DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->where("p.{$sellerCol}", $sellerId)
            ->whereNull('p.deleted_at')
            ->distinct()
            ->pluck('oi.order_id');

        if ($sellerOrderIds->isEmpty()) {
            return response()->json(['success' => true, 'data' => ['heatmap' => []]]);
        }

        $rows = DB::table('orders')
            ->whereIn('id', $sellerOrderIds)
            ->selectRaw("DAYOFWEEK(created_at) - 1 as dow, HOUR(created_at) as hour, COUNT(*) as cnt")
            ->groupBy('dow', 'hour')
            ->get();

        // Build 7×24 grid
        $grid = [];
        for ($d = 0; $d < 7; $d++) {
            for ($h = 0; $h < 24; $h++) {
                $grid["{$d}_{$h}"] = 0;
            }
        }
        foreach ($rows as $r) {
            $grid["{$r->dow}_{$r->hour}"] = (int)$r->cnt;
        }

        $days  = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        $heatmap = [];
        foreach ($days as $di => $day) {
            $hourData = [];
            for ($h = 0; $h < 24; $h++) {
                $hourData[] = ['hour' => $h, 'count' => $grid["{$di}_{$h}"] ?? 0];
            }
            $heatmap[] = ['day' => $day, 'hours' => $hourData];
        }

        return response()->json([
            'success' => true,
            'data'    => ['heatmap' => $heatmap],
        ]);
    }
}
<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SellerDashboardController extends Controller
{
    private function sellerId(): int
    {
        return (int) auth()->id();
    }

    public function index()
    {
        $now      = Carbon::now();
        $sellerId = $this->sellerId();

        // ── Detect seller column (no static cache — fresh every request) ──────
        $productCols = DB::select("SHOW COLUMNS FROM products");
        $productColNames = array_map(fn($c) => $c->Field, $productCols);
        $sellerCol = in_array('seller_id', $productColNames) ? 'seller_id' : 'user_id';

        // ── Detect order_items total column ───────────────────────────────────
        $itemCols = DB::select("SHOW COLUMNS FROM order_items");
        $itemColNames = array_map(fn($c) => $c->Field, $itemCols);

        // Build the safest possible total expression from actual columns
        $totalExpr = 'COALESCE(';
        $parts = [];
        if (in_array('total', $itemColNames))      $parts[] = 'oi.total';
        if (in_array('subtotal', $itemColNames))   $parts[] = 'oi.subtotal';
        if (in_array('line_total', $itemColNames)) $parts[] = 'oi.line_total';
        // Always add price*quantity as ultimate fallback
        if (in_array('price', $itemColNames) && in_array('quantity', $itemColNames)) {
            $parts[] = 'oi.price * oi.quantity';
        } elseif (in_array('unit_price', $itemColNames) && in_array('quantity', $itemColNames)) {
            $parts[] = 'oi.unit_price * oi.quantity';
        }
        $parts[] = '0';
        $totalExpr .= implode(', ', $parts) . ')';

        // ── Check if users table has state column ─────────────────────────────
        $userCols = DB::select("SHOW COLUMNS FROM users");
        $userColNames = array_map(fn($c) => $c->Field, $userCols);
        $hasStateCol = in_array('state', $userColNames);

        // ── Seller's order IDs ─────────────────────────────────────────────────
        $sellerOrderIds = DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->where("p.{$sellerCol}", $sellerId)
            ->whereNull('p.deleted_at')
            ->distinct()
            ->pluck('oi.order_id');

        $totalOrders = $sellerOrderIds->count();

        $pendingOrders = $totalOrders > 0
            ? Order::whereIn('id', $sellerOrderIds)->where('status', 'pending')->count()
            : 0;

        // ── Products KPIs ──────────────────────────────────────────────────────
        $totalProducts = Product::withoutGlobalScopes()
            ->where($sellerCol, $sellerId)
            ->count();

        $activeProducts = Product::withoutGlobalScopes()
            ->where($sellerCol, $sellerId)
            ->where('is_active', true)
            ->count();

        $pendingProductApprovals = Product::withoutGlobalScopes()
            ->where($sellerCol, $sellerId)
            ->where('is_approved', false)
            ->count();

        // ── Revenue (NO payment_status filter — count all completed/delivered) ─
        // This is intentional: COD orders are often delivered before payment
        // is confirmed. Sellers should see revenue for fulfilled orders.

        $totalRevenue      = 0;
        $revenueThisMonth  = 0;
        $revenueLastMonth  = 0;

        if ($sellerOrderIds->isNotEmpty()) {
            try {
                $totalRevenue = DB::table('order_items as oi')
                    ->join('products as p', 'p.id', '=', 'oi.product_id')
                    ->join('orders as o',   'o.id', '=', 'oi.order_id')
                    ->where("p.{$sellerCol}", $sellerId)
                    ->whereNull('p.deleted_at')
                    ->whereIn('o.status', ['completed', 'delivered'])
                    ->sum(DB::raw($totalExpr));
            } catch (\Exception $e) {
                $totalRevenue = 0;
            }

            try {
                $revenueThisMonth = DB::table('order_items as oi')
                    ->join('products as p', 'p.id', '=', 'oi.product_id')
                    ->join('orders as o',   'o.id', '=', 'oi.order_id')
                    ->where("p.{$sellerCol}", $sellerId)
                    ->whereNull('p.deleted_at')
                    ->whereIn('o.status', ['completed', 'delivered'])
                    ->whereBetween('o.created_at', [
                        $now->copy()->startOfMonth(),
                        $now->copy()->endOfMonth(),
                    ])
                    ->sum(DB::raw($totalExpr));
            } catch (\Exception $e) {
                $revenueThisMonth = 0;
            }

            try {
                $revenueLastMonth = DB::table('order_items as oi')
                    ->join('products as p', 'p.id', '=', 'oi.product_id')
                    ->join('orders as o',   'o.id', '=', 'oi.order_id')
                    ->where("p.{$sellerCol}", $sellerId)
                    ->whereNull('p.deleted_at')
                    ->whereIn('o.status', ['completed', 'delivered'])
                    ->whereBetween('o.created_at', [
                        $now->copy()->subMonth()->startOfMonth(),
                        $now->copy()->subMonth()->endOfMonth(),
                    ])
                    ->sum(DB::raw($totalExpr));
            } catch (\Exception $e) {
                $revenueLastMonth = 0;
            }
        }

        $revenueGrowth = $revenueLastMonth > 0
            ? round((($revenueThisMonth - $revenueLastMonth) / $revenueLastMonth) * 100, 1)
            : ($revenueThisMonth > 0 ? 100.0 : 0.0);

        // ── Order status distribution ──────────────────────────────────────────
        $orderStatusDistribution = (object) [];
        if ($sellerOrderIds->isNotEmpty()) {
            try {
                $orderStatusDistribution = Order::whereIn('id', $sellerOrderIds)
                    ->select('status', DB::raw('count(*) as count'))
                    ->groupBy('status')
                    ->get()
                    ->mapWithKeys(fn($item) => [$item->status => (int) $item->count]);
            } catch (\Exception $e) {
                $orderStatusDistribution = (object) [];
            }
        }

        // ── Monthly revenue — last 12 months ───────────────────────────────────
        $monthlyRevenue = [];
        if ($sellerOrderIds->isNotEmpty()) {
            try {
                $rawMonthly = DB::table('order_items as oi')
                    ->join('products as p', 'p.id', '=', 'oi.product_id')
                    ->join('orders as o',   'o.id', '=', 'oi.order_id')
                    ->where("p.{$sellerCol}", $sellerId)
                    ->whereNull('p.deleted_at')
                    ->whereIn('o.status', ['completed', 'delivered'])
                    ->where('o.created_at', '>=', $now->copy()->subMonths(11)->startOfMonth())
                    ->select(
                        DB::raw("DATE_FORMAT(o.created_at, '%Y-%m') as month"),
                        DB::raw("SUM({$totalExpr}) as revenue"),
                        DB::raw('COUNT(DISTINCT oi.order_id) as orders')
                    )
                    ->groupBy('month')
                    ->orderBy('month')
                    ->get()
                    ->keyBy('month');

                $monthlyRevenue = collect();
                for ($i = 11; $i >= 0; $i--) {
                    $key   = $now->copy()->subMonths($i)->format('Y-m');
                    $label = $now->copy()->subMonths($i)->format('M Y');
                    $monthlyRevenue->push([
                        'month'   => $label,
                        'revenue' => isset($rawMonthly[$key]) ? round((float) $rawMonthly[$key]->revenue, 3) : 0,
                        'orders'  => isset($rawMonthly[$key]) ? (int) $rawMonthly[$key]->orders : 0,
                    ]);
                }
            } catch (\Exception $e) {
                $monthlyRevenue = [];
            }
        } else {
            // Return empty 12-month skeleton so chart renders cleanly
            $monthlyRevenue = collect();
            for ($i = 11; $i >= 0; $i--) {
                $monthlyRevenue->push([
                    'month'   => $now->copy()->subMonths($i)->format('M Y'),
                    'revenue' => 0,
                    'orders'  => 0,
                ]);
            }
        }

        // ── Top 5 clients ──────────────────────────────────────────────────────
        $topClients = [];
        if ($sellerOrderIds->isNotEmpty()) {
            try {
                $clientSelect = [
                    'u.id',
                    'u.name',
                    'u.email',
                    DB::raw("SUM({$totalExpr}) as total_revenue"),
                    DB::raw('COUNT(DISTINCT oi.order_id) as total_orders'),
                ];
                $clientGroup = ['u.id', 'u.name', 'u.email'];

                if ($hasStateCol) {
                    $clientSelect[] = 'u.state';
                    $clientGroup[]  = 'u.state';
                }

                $topClients = DB::table('order_items as oi')
                    ->join('products as p', 'p.id', '=', 'oi.product_id')
                    ->join('orders as o',   'o.id', '=', 'oi.order_id')
                    ->join('users as u',    'u.id', '=', 'o.user_id')
                    ->where("p.{$sellerCol}", $sellerId)
                    ->whereNull('p.deleted_at')
                    ->whereIn('o.status', ['completed', 'delivered'])
                    ->select($clientSelect)
                    ->groupBy($clientGroup)
                    ->orderByDesc('total_revenue')
                    ->limit(5)
                    ->get()
                    ->map(fn($r) => [
                        'id'            => $r->id,
                        'name'          => $r->name,
                        'email'         => $r->email,
                        'state'         => $r->state ?? null,
                        'total_revenue' => round((float) $r->total_revenue, 3),
                        'total_orders'  => (int) $r->total_orders,
                    ]);
            } catch (\Exception $e) {
                $topClients = [];
            }
        }

        // ── Top 5 wilayas ──────────────────────────────────────────────────────
        $topWilayas = [];
        if ($sellerOrderIds->isNotEmpty()) {
            try {
                if ($hasStateCol) {
                    $wilayaExpr = 'COALESCE(NULLIF(o.wilaya, ""), u.state, "Unknown")';
                    $topWilayasQuery = DB::table('order_items as oi')
                        ->join('products as p', 'p.id', '=', 'oi.product_id')
                        ->join('orders as o',   'o.id', '=', 'oi.order_id')
                        ->join('users as u',    'u.id', '=', 'o.user_id')
                        ->where("p.{$sellerCol}", $sellerId)
                        ->whereNull('p.deleted_at')
                        ->whereIn('o.status', ['completed', 'delivered']);
                } else {
                    $wilayaExpr = 'COALESCE(NULLIF(o.wilaya, ""), "Unknown")';
                    $topWilayasQuery = DB::table('order_items as oi')
                        ->join('products as p', 'p.id', '=', 'oi.product_id')
                        ->join('orders as o',   'o.id', '=', 'oi.order_id')
                        ->where("p.{$sellerCol}", $sellerId)
                        ->whereNull('p.deleted_at')
                        ->whereIn('o.status', ['completed', 'delivered']);
                }

                $topWilayas = $topWilayasQuery
                    ->select(
                        DB::raw("{$wilayaExpr} as wilaya"),
                        DB::raw("SUM({$totalExpr}) as revenue"),
                        DB::raw('COUNT(DISTINCT oi.order_id) as orders')
                    )
                    ->groupBy('wilaya')
                    ->orderByDesc('revenue')
                    ->limit(5)
                    ->get()
                    ->map(fn($r) => [
                        'wilaya'  => $r->wilaya,
                        'revenue' => round((float) $r->revenue, 3),
                        'orders'  => (int) $r->orders,
                    ]);
            } catch (\Exception $e) {
                $topWilayas = [];
            }
        }

        // ── Recent orders ──────────────────────────────────────────────────────
        $recentOrders = [];
        if ($sellerOrderIds->isNotEmpty()) {
            try {
                $recentOrders = Order::with(['user:id,name,email'])
                    ->whereIn('id', $sellerOrderIds)
                    ->latest()
                    ->limit(5)
                    ->get(['id', 'user_id', 'order_number', 'total_amount', 'status', 'payment_status', 'created_at']);
            } catch (\Exception $e) {
                $recentOrders = [];
            }
        }

        // ── Response ───────────────────────────────────────────────────────────
        return response()->json([
            'success' => true,
            'data'    => [
                'summary' => [
                    'total_revenue'             => round((float) $totalRevenue, 3),
                    'total_orders'              => $totalOrders,
                    'pending_orders'            => $pendingOrders,
                    'total_products'            => $totalProducts,
                    'active_products'           => $activeProducts,
                    'pending_product_approvals' => $pendingProductApprovals,
                    'revenue_this_month'        => round((float) $revenueThisMonth, 3),
                    'revenue_last_month'        => round((float) $revenueLastMonth, 3),
                    'revenue_growth'            => $revenueGrowth,
                ],
                'charts' => [
                    'monthly_revenue' => $monthlyRevenue,
                ],
                'order_status_distribution' => $orderStatusDistribution,
                'top_clients'               => $topClients,
                'top_wilayas'               => $topWilayas,
                'recent_orders'             => $recentOrders,
            ],
        ]);
    }
}
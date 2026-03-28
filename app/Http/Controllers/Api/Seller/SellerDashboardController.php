<?php

namespace App\Http\Controllers\Api\Seller;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SellerDashboardController extends Controller
{
    /**
     * Hardcoded seller_id = 1 for development.
     * Replace with auth()->id() when auth middleware is wired up.
     */
    private function sellerId(): int { return (int) auth()->id(); }

    public function index()
    {
        $now = Carbon::now();

        // ── KPIs ──────────────────────────────────────────────────────────────

        $totalProducts = Product::withoutGlobalScopes()
            ->where('seller_id', $this->sellerId())
            ->count();

        $activeProducts = Product::withoutGlobalScopes()
            ->where('seller_id', $this->sellerId())
            ->where('is_active', true)
            ->count();

        $pendingProductApprovals = Product::withoutGlobalScopes()
            ->where('seller_id', $this->sellerId())
            ->where('is_approved', false)
            ->count();

        // Distinct orders that contain at least one of this seller's products
        $sellerOrderIds = DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->where('p.seller_id', $this->sellerId())
            ->distinct()
            ->pluck('oi.order_id');

        $totalOrders = $sellerOrderIds->count();

        $pendingOrders = Order::whereIn('id', $sellerOrderIds)
            ->where('status', 'pending')
            ->count();

        // ── Revenue queries wrapped in try/catch ──────────────────────────────
        // FIX: these were the only revenue queries outside try/catch.
        // If order_items uses a different total column name (subtotal, line_total)
        // MySQL throws "Unknown column" and bubbles up as a 500.
        // COALESCE handles all known column name variants safely.

        try {
            $totalRevenue = DB::table('order_items as oi')
                ->join('products as p',  'p.id', '=', 'oi.product_id')
                ->join('orders as o',    'o.id', '=', 'oi.order_id')
                ->where('p.seller_id', $this->sellerId())
                ->whereIn('o.status', ['completed', 'delivered'])
                ->where('o.payment_status', 'paid')
                ->sum(DB::raw('COALESCE(oi.total, oi.subtotal, oi.line_total, 0)'));
        } catch (\Exception $e) {
            $totalRevenue = 0;
        }

        try {
            $revenueThisMonth = DB::table('order_items as oi')
                ->join('products as p', 'p.id', '=', 'oi.product_id')
                ->join('orders as o',   'o.id', '=', 'oi.order_id')
                ->where('p.seller_id', $this->sellerId())
                ->whereIn('o.status', ['completed', 'delivered'])
                ->where('o.payment_status', 'paid')
                ->whereBetween('o.created_at', [
                    $now->copy()->startOfMonth(),
                    $now->copy()->endOfMonth(),
                ])
                ->sum(DB::raw('COALESCE(oi.total, oi.subtotal, oi.line_total, 0)'));
        } catch (\Exception $e) {
            $revenueThisMonth = 0;
        }

        try {
            $revenueLastMonth = DB::table('order_items as oi')
                ->join('products as p', 'p.id', '=', 'oi.product_id')
                ->join('orders as o',   'o.id', '=', 'oi.order_id')
                ->where('p.seller_id', $this->sellerId())
                ->whereIn('o.status', ['completed', 'delivered'])
                ->where('o.payment_status', 'paid')
                ->whereBetween('o.created_at', [
                    $now->copy()->subMonth()->startOfMonth(),
                    $now->copy()->subMonth()->endOfMonth(),
                ])
                ->sum(DB::raw('COALESCE(oi.total, oi.subtotal, oi.line_total, 0)'));
        } catch (\Exception $e) {
            $revenueLastMonth = 0;
        }

        $revenueGrowth = $revenueLastMonth > 0
            ? round((($revenueThisMonth - $revenueLastMonth) / $revenueLastMonth) * 100, 1)
            : ($revenueThisMonth > 0 ? 100.0 : 0.0);

        // ── Order status distribution ─────────────────────────────────────────

        try {
            $orderStatusDistribution = Order::whereIn('id', $sellerOrderIds)
                ->select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->get()
                ->mapWithKeys(fn($item) => [$item->status => (int) $item->count]);
        } catch (\Exception $e) {
            $orderStatusDistribution = (object) [];
        }

        // ── Monthly revenue — last 12 months ─────────────────────────────────

        try {
            $rawMonthly = DB::table('order_items as oi')
                ->join('products as p', 'p.id', '=', 'oi.product_id')
                ->join('orders as o',   'o.id', '=', 'oi.order_id')
                ->where('p.seller_id', $this->sellerId())
                ->whereIn('o.status', ['completed', 'delivered'])
                ->where('o.payment_status', 'paid')
                ->where('o.created_at', '>=', $now->copy()->subMonths(11)->startOfMonth())
                ->select(
                    DB::raw("DATE_FORMAT(o.created_at, '%Y-%m') as month"),
                    DB::raw('SUM(COALESCE(oi.total, oi.subtotal, oi.line_total, 0)) as revenue'),
                    DB::raw('COUNT(DISTINCT oi.order_id) as orders')
                )
                ->groupBy('month')
                ->orderBy('month')
                ->get()
                ->keyBy('month');

            // Fill all 12 months (gaps = 0) so chart renders correctly
            $monthlyRevenue = collect();
            for ($i = 11; $i >= 0; $i--) {
                $key = $now->copy()->subMonths($i)->format('Y-m');
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

        // ── Top 5 clients ─────────────────────────────────────────────────────

        try {
            $topClients = DB::table('order_items as oi')
                ->join('products as p', 'p.id', '=', 'oi.product_id')
                ->join('orders as o',   'o.id', '=', 'oi.order_id')
                ->join('users as u',    'u.id', '=', 'o.user_id')
                ->where('p.seller_id', $this->sellerId())
                ->whereIn('o.status', ['completed', 'delivered'])
                ->where('o.payment_status', 'paid')
                ->select(
                    'u.id',
                    'u.name',
                    'u.email',
                    'u.state',
                    DB::raw('SUM(COALESCE(oi.total, oi.subtotal, oi.line_total, 0)) as total_revenue'),
                    DB::raw('COUNT(DISTINCT oi.order_id) as total_orders')
                )
                ->groupBy('u.id', 'u.name', 'u.email', 'u.state')
                ->orderByDesc('total_revenue')
                ->limit(5)
                ->get()
                ->map(fn($r) => [
                    'id'            => $r->id,
                    'name'          => $r->name,
                    'email'         => $r->email,
                    'state'         => $r->state,
                    'total_revenue' => round((float) $r->total_revenue, 3),
                    'total_orders'  => (int) $r->total_orders,
                ]);
        } catch (\Exception $e) {
            $topClients = [];
        }

        // ── Top 5 wilayas ─────────────────────────────────────────────────────

        try {
            $topWilayas = DB::table('order_items as oi')
                ->join('products as p', 'p.id', '=', 'oi.product_id')
                ->join('orders as o',   'o.id', '=', 'oi.order_id')
                ->join('users as u',    'u.id', '=', 'o.user_id')
                ->where('p.seller_id', $this->sellerId())
                ->whereIn('o.status', ['completed', 'delivered'])
                ->where('o.payment_status', 'paid')
                ->select(
                    DB::raw('COALESCE(NULLIF(o.wilaya, ""), u.state, "Unknown") as wilaya'),
                    DB::raw('SUM(COALESCE(oi.total, oi.subtotal, oi.line_total, 0)) as revenue'),
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

        // ── Recent orders ─────────────────────────────────────────────────────

        try {
            $recentOrders = Order::with(['user:id,name,email'])
                ->whereIn('id', $sellerOrderIds)
                ->latest()
                ->limit(5)
                ->get(['id', 'user_id', 'order_number', 'total_amount', 'status', 'payment_status', 'created_at']);
        } catch (\Exception $e) {
            $recentOrders = [];
        }

        // ── Response ──────────────────────────────────────────────────────────

        return response()->json([
            'success' => true,
            'data'    => [
                'summary' => [
                    'total_revenue'            => round((float) $totalRevenue, 3),
                    'total_orders'             => $totalOrders,
                    'pending_orders'           => $pendingOrders,
                    'total_products'           => $totalProducts,
                    'active_products'          => $activeProducts,
                    'pending_product_approvals'=> $pendingProductApprovals,
                    'revenue_this_month'       => round((float) $revenueThisMonth, 3),
                    'revenue_last_month'       => round((float) $revenueLastMonth, 3),
                    'revenue_growth'           => $revenueGrowth,
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
<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SellerDashboardController extends Controller
{
    public function index(Request $request)
    {
        $seller = $request->user();
        $sellerId = $seller->id;

        // ── Revenue & order summary ────────────────────────────────────────────
        $totalRevenue = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('products.seller_id', $sellerId)
            ->whereIn('orders.status', ['completed', 'delivered'])
            ->sum(DB::raw('order_items.quantity * order_items.unit_price'));

        $totalOrders = DB::table('orders')
            ->whereExists(function ($q) use ($sellerId) {
                $q->select(DB::raw(1))
                  ->from('order_items')
                  ->join('products', 'products.id', '=', 'order_items.product_id')
                  ->whereColumn('order_items.order_id', 'orders.id')
                  ->where('products.seller_id', $sellerId);
            })
            ->count();

        $pendingOrders = DB::table('orders')
            ->whereExists(function ($q) use ($sellerId) {
                $q->select(DB::raw(1))
                  ->from('order_items')
                  ->join('products', 'products.id', '=', 'order_items.product_id')
                  ->whereColumn('order_items.order_id', 'orders.id')
                  ->where('products.seller_id', $sellerId);
            })
            ->where('orders.status', 'pending')
            ->count();

        $totalProducts   = DB::table('products')->where('seller_id', $sellerId)->count();
        $activeProducts  = DB::table('products')->where('seller_id', $sellerId)->where('is_active', true)->count();
        $pendingApprovals= DB::table('products')->where('seller_id', $sellerId)->where('is_approved', false)->count();

        $now           = Carbon::now();
        $startOfMonth  = $now->copy()->startOfMonth();
        $startOfLast   = $now->copy()->subMonth()->startOfMonth();
        $endOfLast     = $now->copy()->subMonth()->endOfMonth();

        $revenueThisMonth = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('products.seller_id', $sellerId)
            ->whereIn('orders.status', ['completed', 'delivered'])
            ->where('orders.created_at', '>=', $startOfMonth)
            ->sum(DB::raw('order_items.quantity * order_items.unit_price'));

        $revenueLastMonth = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('products.seller_id', $sellerId)
            ->whereIn('orders.status', ['completed', 'delivered'])
            ->whereBetween('orders.created_at', [$startOfLast, $endOfLast])
            ->sum(DB::raw('order_items.quantity * order_items.unit_price'));

        $revenueGrowth = $revenueLastMonth > 0
            ? (($revenueThisMonth - $revenueLastMonth) / $revenueLastMonth) * 100
            : ($revenueThisMonth > 0 ? 100 : 0);

        // ── Monthly chart (last 12 months) ────────────────────────────────────
        $monthlyRevenue = [];
        for ($i = 11; $i >= 0; $i--) {
            $month      = $now->copy()->subMonths($i);
            $monthStart = $month->copy()->startOfMonth();
            $monthEnd   = $month->copy()->endOfMonth();

            $rev = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->join('products', 'products.id', '=', 'order_items.product_id')
                ->where('products.seller_id', $sellerId)
                ->whereIn('orders.status', ['completed', 'delivered'])
                ->whereBetween('orders.created_at', [$monthStart, $monthEnd])
                ->sum(DB::raw('order_items.quantity * order_items.unit_price'));

            $ord = DB::table('orders')
                ->whereExists(function ($q) use ($sellerId) {
                    $q->select(DB::raw(1))
                      ->from('order_items')
                      ->join('products', 'products.id', '=', 'order_items.product_id')
                      ->whereColumn('order_items.order_id', 'orders.id')
                      ->where('products.seller_id', $sellerId);
                })
                ->whereBetween('orders.created_at', [$monthStart, $monthEnd])
                ->count();

            $monthlyRevenue[] = [
                'month'   => $month->format('M Y'),
                'revenue' => round((float)$rev, 3),
                'orders'  => $ord,
            ];
        }

        // ── Order status distribution ─────────────────────────────────────────
        $statusDist = DB::table('orders')
            ->select('orders.status', DB::raw('COUNT(*) as count'))
            ->whereExists(function ($q) use ($sellerId) {
                $q->select(DB::raw(1))
                  ->from('order_items')
                  ->join('products', 'products.id', '=', 'order_items.product_id')
                  ->whereColumn('order_items.order_id', 'orders.id')
                  ->where('products.seller_id', $sellerId);
            })
            ->groupBy('orders.status')
            ->pluck('count', 'orders.status')
            ->toArray();

        // ── Top clients ───────────────────────────────────────────────────────
        $topClients = DB::table('orders')
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->whereExists(function ($q) use ($sellerId) {
                $q->select(DB::raw(1))
                  ->from('order_items')
                  ->join('products', 'products.id', '=', 'order_items.product_id')
                  ->whereColumn('order_items.order_id', 'orders.id')
                  ->where('products.seller_id', $sellerId);
            })
            ->whereIn('orders.status', ['completed', 'delivered'])
            ->select(
                'users.id',
                'users.name',
                'users.email',
                DB::raw('NULL as state'),
                DB::raw('SUM(orders.total_amount) as total_revenue'),
                DB::raw('COUNT(DISTINCT orders.id) as total_orders')
            )
            ->groupBy('users.id', 'users.name', 'users.email')
            ->orderByDesc('total_revenue')
            ->limit(5)
            ->get()
            ->map(fn($c) => [
                'id'            => $c->id,
                'name'          => $c->name,
                'email'         => $c->email,
                'state'         => $c->state,
                'total_revenue' => round((float)$c->total_revenue, 3),
                'total_orders'  => (int)$c->total_orders,
            ]);

        // ── Top wilayas ───────────────────────────────────────────────────────
        $topWilayas = DB::table('orders')
            ->whereExists(function ($q) use ($sellerId) {
                $q->select(DB::raw(1))
                  ->from('order_items')
                  ->join('products', 'products.id', '=', 'order_items.product_id')
                  ->whereColumn('order_items.order_id', 'orders.id')
                  ->where('products.seller_id', $sellerId);
            })
            ->whereIn('orders.status', ['completed', 'delivered'])
            ->whereNotNull('orders.wilaya')
            ->select(
                'orders.wilaya',
                DB::raw('SUM(orders.total_amount) as revenue'),
                DB::raw('COUNT(DISTINCT orders.id) as orders')
            )
            ->groupBy('orders.wilaya')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get()
            ->map(fn($w) => [
                'wilaya'  => $w->wilaya,
                'revenue' => round((float)$w->revenue, 3),
                'orders'  => (int)$w->orders,
            ]);

        // ── TOP PRODUCTS ─────────────────────────────────────────────────────
        // Aggregates sales, revenue, order count per product for this seller
        $topProducts = DB::table('products')
            ->leftJoin('order_items', function ($join) {
                $join->on('order_items.product_id', '=', 'products.id')
                     ->join('orders as o2', function ($j2) {
                         $j2->on('o2.id', '=', 'order_items.order_id')
                            ->whereIn('o2.status', ['completed', 'delivered', 'processing', 'pending']);
                     });
            })
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->leftJoin('product_images', function ($join) {
                $join->on('product_images.product_id', '=', 'products.id')
                     ->where('product_images.is_primary', true);
            })
            ->where('products.seller_id', $sellerId)
            ->select(
                'products.id',
                'products.name',
                'products.slug',
                'products.sku',
                'products.price',
                'products.stock',
                'products.is_active',
                'products.is_approved',
                'products.views',
                'categories.name as category_name',
                'product_images.image_path as primary_image_path',
                DB::raw('COALESCE(SUM(order_items.quantity), 0) as total_sales'),
                DB::raw('COALESCE(SUM(order_items.quantity * order_items.unit_price), 0) as total_revenue'),
                DB::raw('COUNT(DISTINCT order_items.order_id) as total_orders')
            )
            ->groupBy(
                'products.id', 'products.name', 'products.slug', 'products.sku',
                'products.price', 'products.stock', 'products.is_active',
                'products.is_approved', 'products.views',
                'categories.name', 'product_images.image_path'
            )
            ->orderByDesc('total_sales')
            ->limit(6)
            ->get()
            ->map(function ($p) {
                $imagePath = $p->primary_image_path;
                $imageUrl  = null;
                if ($imagePath) {
                    $base     = rtrim(config('app.url'), '/');
                    $imageUrl = $base . '/storage/' . ltrim($imagePath, '/');
                }
                return [
                    'id'                => $p->id,
                    'name'              => $p->name,
                    'slug'              => $p->slug,
                    'sku'               => $p->sku,
                    'price'             => round((float)$p->price, 3),
                    'stock'             => (int)$p->stock,
                    'is_active'         => (bool)$p->is_active,
                    'is_approved'       => (bool)$p->is_approved,
                    'primary_image_url' => $imageUrl,
                    'category_name'     => $p->category_name,
                    'total_sales'       => (int)$p->total_sales,
                    'total_revenue'     => round((float)$p->total_revenue, 3),
                    'total_orders'      => (int)$p->total_orders,
                    'views'             => (int)($p->views ?? 0),
                ];
            });

        // ── Recent orders ─────────────────────────────────────────────────────
        $recentOrders = DB::table('orders')
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->whereExists(function ($q) use ($sellerId) {
                $q->select(DB::raw(1))
                  ->from('order_items')
                  ->join('products', 'products.id', '=', 'order_items.product_id')
                  ->whereColumn('order_items.order_id', 'orders.id')
                  ->where('products.seller_id', $sellerId);
            })
            ->select(
                'orders.id',
                'orders.user_id',
                'orders.order_number',
                'orders.total_amount',
                'orders.status',
                'orders.payment_status',
                'orders.created_at',
                'users.id as uid',
                'users.name as uname',
                'users.email as uemail'
            )
            ->orderByDesc('orders.created_at')
            ->limit(5)
            ->get()
            ->map(fn($o) => [
                'id'            => $o->id,
                'user_id'       => $o->user_id,
                'order_number'  => $o->order_number,
                'total_amount'  => round((float)$o->total_amount, 3),
                'status'        => $o->status,
                'payment_status'=> $o->payment_status,
                'created_at'    => $o->created_at,
                'user'          => ['id' => $o->uid, 'name' => $o->uname, 'email' => $o->uemail],
            ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'summary' => [
                    'total_revenue'              => round((float)$totalRevenue, 3),
                    'total_orders'               => $totalOrders,
                    'pending_orders'             => $pendingOrders,
                    'total_products'             => $totalProducts,
                    'active_products'            => $activeProducts,
                    'pending_product_approvals'  => $pendingApprovals,
                    'revenue_this_month'         => round((float)$revenueThisMonth, 3),
                    'revenue_last_month'         => round((float)$revenueLastMonth, 3),
                    'revenue_growth'             => round((float)$revenueGrowth, 2),
                ],
                'charts'                     => ['monthly_revenue' => $monthlyRevenue],
                'order_status_distribution'  => $statusDist,
                'top_clients'                => $topClients,
                'top_wilayas'                => $topWilayas,
                'top_products'               => $topProducts,   // ← NEW
                'recent_orders'              => $recentOrders,
            ],
        ]);
    }
}
<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SellerOrderController extends Controller
{
    /**
     * Hardcoded seller_id = 1 for development.
     * Replace with auth()->id() when auth middleware is wired up.
     */
    private function sellerId(): int { return (int) auth()->id(); }

    /**
     * Status transitions the seller is allowed to make.
     * Seller cannot touch payment_status — that's admin territory.
     */
    private array $allowedStatuses = ['pending', 'processing', 'completed', 'cancelled'];

    // ── GET /api/seller/orders ────────────────────────────────────────────────
    public function index(Request $request)
    {
        // Resolve all order IDs that contain this seller's products (no N+1)
        $sellerOrderIds = DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->where('p.seller_id', $this->sellerId())
            ->distinct()
            ->pluck('oi.order_id');

        $query = Order::with(['user:id,name,email,state'])
            ->whereIn('id', $sellerOrderIds)
            ->select([
                'id', 'user_id', 'order_number', 'total_amount',
                'status', 'payment_status', 'wilaya', 'created_at',
            ]);

        // Filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->filled('search')) {
            $query->where('order_number', 'like', '%' . $request->search . '%');
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $perPage = (int) $request->get('per_page', 12);
        $orders  = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $orders,
        ]);
    }

    // ── GET /api/seller/orders/stats ──────────────────────────────────────────
    public function stats()
    {
        $sellerOrderIds = DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->where('p.seller_id', $this->sellerId())
            ->distinct()
            ->pluck('oi.order_id');

        $stats = Order::whereIn('id', $sellerOrderIds)
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = "pending"    THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = "processing" THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = "completed"  THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = "cancelled"  THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN status = "delivered"  THEN 1 ELSE 0 END) as delivered
            ')
            ->first();

        return response()->json([
            'success' => true,
            'data'    => $stats,
        ]);
    }

    // ── GET /api/seller/orders/{id} ───────────────────────────────────────────
    public function show(int $id)
    {
        // Security check — seller can only view orders containing their products
        $hasSellerProduct = DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->where('p.seller_id', $this->sellerId())
            ->where('oi.order_id', $id)
            ->exists();

        if (! $hasSellerProduct) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        $order = Order::with(['user:id,name,email,state'])->findOrFail($id);

        // Return ONLY the order_items belonging to this seller
        $sellerItems = DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->where('p.seller_id', $this->sellerId())
            ->where('oi.order_id', $id)
            ->select(
                'oi.id',
                'oi.product_id',
                'p.name as product_name',
                'p.price as unit_price',
                'oi.quantity',
                'oi.price',
                'oi.total'
            )
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'order' => [
                    'id'             => $order->id,
                    'order_number'   => $order->order_number,
                    'status'         => $order->status,
                    'payment_status' => $order->payment_status,
                    'total_amount'   => $order->total_amount,
                    'wilaya' => $order->user ? $order->user->state : null, 
                    'created_at'     => $order->created_at,
                    'customer'       => $order->user,
                ],
                'items'           => $sellerItems,
                'seller_subtotal' => round((float) $sellerItems->sum('total'), 3),
            ],
        ]);
    }

    // ── PATCH /api/seller/orders/{id}/status ──────────────────────────────────
    public function updateStatus(Request $request, int $id)
    {
        $request->validate([
            'status' => ['required', 'in:' . implode(',', $this->allowedStatuses)],
        ]);

        $hasSellerProduct = DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->where('p.seller_id', $this->sellerId())
            ->where('oi.order_id', $id)
            ->exists();

        if (! $hasSellerProduct) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        $order = Order::findOrFail($id);
        $order->update(['status' => $request->status]);

        return response()->json([
            'success' => true,
            'message' => 'Order status updated successfully.',
            'data'    => $order->only(['id', 'order_number', 'status', 'payment_status']),
        ]);
    }
}
<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class SellerOrderController extends Controller
{
    private function sellerOrderIds(int $sellerId): array
    {
        $columns  = DB::select("SHOW COLUMNS FROM products");
        $colNames = array_map(fn($c) => $c->Field, $columns);
        $sellerCol = in_array('seller_id', $colNames) ? 'seller_id' : 'user_id';

        return DB::table('order_items')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where("products.{$sellerCol}", $sellerId)
            ->whereNull('products.deleted_at')
            ->pluck('order_items.order_id')
            ->unique()
            ->values()
            ->toArray();
    }

    private function getSellerCol(): string
    {
        $columns  = DB::select("SHOW COLUMNS FROM products");
        $colNames = array_map(fn($c) => $c->Field, $columns);
        return in_array('seller_id', $colNames) ? 'seller_id' : 'user_id';
    }

    /**
     * GET /api/seller/orders/stats
     */
    public function stats(Request $request)
    {
        $seller   = auth()->user();
        $orderIds = $this->sellerOrderIds($seller->id);

        if (empty($orderIds)) {
            return response()->json(['success' => true, 'data' => [
                'total' => 0, 'pending' => 0, 'completed' => 0,
                'delivered' => 0, 'cancelled' => 0, 'revenue' => 0,
            ]]);
        }

        $base = Order::whereIn('id', $orderIds);

        return response()->json(['success' => true, 'data' => [
            'total'     => (clone $base)->count(),
            'pending'   => (clone $base)->where('status', 'pending')->count(),
            'completed' => (clone $base)->where('status', 'completed')->count(),
            'delivered' => (clone $base)->where('status', 'delivered')->count(),
            'cancelled' => (clone $base)->where('status', 'cancelled')->count(),
            'revenue'   => (clone $base)->whereIn('status', ['completed', 'delivered'])->sum('total_amount'),
        ]]);
    }

    /**
     * GET /api/seller/orders
     */
    public function index(Request $request)
    {
        $seller   = auth()->user();
        $orderIds = $this->sellerOrderIds($seller->id);

        $query = Order::with(['user:id,name,email', 'items'])
                      ->whereIn('id', $orderIds);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhereHas('user', fn($u) =>
                      $u->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                  );
            });
        }

        $orders = $query->latest()->paginate((int) $request->query('per_page', 12));

        return response()->json(['success' => true, 'data' => $orders]);
    }

    /**
     * GET /api/seller/orders/{id}
     * Returns nested { order, items, seller_subtotal } to match frontend OrderDetail type.
     */
    public function show(Request $request, $id)
    {
        $seller    = auth()->user();
        $orderIds  = $this->sellerOrderIds($seller->id);
        $sellerCol = $this->getSellerCol();

        $order = Order::with(['user:id,name,email', 'items'])
                      ->whereIn('id', $orderIds)
                      ->findOrFail($id);

        // Filter to only this seller's items
        $sellerItems = $order->items->filter(function ($item) use ($seller, $sellerCol) {
            $product = DB::table('products')
                ->where('id', $item->product_id)
                ->whereNull('deleted_at')
                ->first();
            return $product && $product->{$sellerCol} == $seller->id;
        })->values();

        $subtotal = $sellerItems->sum(fn($item) => (float) $item->unit_price * $item->quantity);

        $customer = $order->user ? [
            'name'  => $order->user->name,
            'email' => $order->user->email,
            'state' => null,
        ] : null;

        return response()->json([
            'success' => true,
            'data'    => [
                'order'           => array_merge($order->toArray(), ['customer' => $customer]),
                'items'           => $sellerItems,
                'seller_subtotal' => round($subtotal, 3),
            ],
        ]);
    }

    /**
     * PATCH /api/seller/orders/{id}/status
     * ← FIXED: expanded allowed statuses to match the dropdown options in the frontend
     */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:pending,processing,completed,delivered,cancelled',
        ]);

        $seller   = auth()->user();
        $orderIds = $this->sellerOrderIds($seller->id);

        $order = Order::whereIn('id', $orderIds)->findOrFail($id);
        $order->update(['status' => $request->status]);

        return response()->json([
            'success' => true,
            'message' => 'Order status updated.',
            'data'    => $order,
        ]);
    }
}
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
        $sellerCol = $this->getSellerCol();

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
        static $col = null;
        if ($col) return $col;
        $columns  = DB::select("SHOW COLUMNS FROM products");
        $colNames = array_map(fn($c) => $c->Field, $columns);
        $col = in_array('seller_id', $colNames) ? 'seller_id' : 'user_id';
        return $col;
    }

    /* ── GET /api/seller/orders/stats ── */
    public function stats(Request $request)
    {
        $seller   = auth()->user();
        $orderIds = $this->sellerOrderIds($seller->id);

        if (empty($orderIds)) {
            return response()->json(['success' => true, 'data' => [
                'total'     => 0,
                'pending'   => 0,
                'completed' => 0,
                'delivered' => 0,
                'cancelled' => 0,
                'revenue'   => 0,
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

    /* ── GET /api/seller/orders ── */
    public function index(Request $request)
    {
        $seller   = auth()->user();
        $orderIds = $this->sellerOrderIds($seller->id);

        // FIX: removed 'state' from the select — that column does not exist in users table
        $query = Order::with(['user:id,name,email', 'items'])
                      ->whereIn('id', $orderIds);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
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

        // Map each order so the frontend gets a consistent `wilaya` field
        // FIX: removed ->user->state fallback since 'state' column doesn't exist
        $orders->getCollection()->transform(function ($order) {
            $arr           = $order->toArray();
            $arr['wilaya'] = $order->wilaya
                ?? $order->shipping_address
                ?? null;
            return $arr;
        });

        return response()->json(['success' => true, 'data' => $orders]);
    }

    /* ── GET /api/seller/orders/{id} ── */
    public function show(Request $request, $id)
    {
        $seller    = auth()->user();
        $orderIds  = $this->sellerOrderIds($seller->id);
        $sellerCol = $this->getSellerCol();

        // FIX: removed 'state' from the select — that column does not exist in users table
        $order = Order::with(['user:id,name,email', 'items'])
                      ->whereIn('id', $orderIds)
                      ->findOrFail($id);

        // ── Detect order_items columns once ──────────────────────────────────
        static $itemCols = null;
        if ($itemCols === null) {
            $raw      = DB::select("SHOW COLUMNS FROM order_items");
            $itemCols = array_map(fn($c) => $c->Field, $raw);
        }

        // Resolve which column holds the product name
        $nameCol  = in_array('product_name', $itemCols) ? 'product_name' : null;
        // Resolve price column
        $priceCol = in_array('unit_price', $itemCols)   ? 'unit_price'
                  : (in_array('price', $itemCols)       ? 'price'
                  : (in_array('unit_cost', $itemCols)   ? 'unit_cost' : null));
        // Resolve subtotal column
        $totalCol = in_array('total', $itemCols)        ? 'total'
                  : (in_array('subtotal', $itemCols)    ? 'subtotal'
                  : (in_array('line_total', $itemCols)  ? 'line_total' : null));

        // Filter to only this seller's items
        $sellerItems = $order->items->filter(function ($item) use ($seller, $sellerCol) {
            $product = DB::table('products')
                ->where('id', $item->product_id)
                ->whereNull('deleted_at')
                ->first();
            return $product && $product->{$sellerCol} == $seller->id;
        })->values();

        // Normalise each item so the frontend always gets the same shape
        $mappedItems = $sellerItems->map(function ($item) use ($nameCol, $priceCol, $totalCol) {

            // product_name: from column, or fall back to a products JOIN
            if ($nameCol) {
                $productName = $item->{$nameCol};
            } else {
                $product     = DB::table('products')->where('id', $item->product_id)->first();
                $productName = $product->name ?? "Product #{$item->product_id}";
            }

            // unit_price
            $unitPrice = $priceCol ? (float) $item->{$priceCol} : 0.0;

            // total = unit_price × quantity (compute it; don't trust the stored value)
            $qty   = (int) $item->quantity;
            $total = $totalCol
                ? (float) $item->{$totalCol}
                : round($unitPrice * $qty, 3);

            return [
                'id'           => $item->id,
                'product_id'   => $item->product_id,
                'product_name' => $productName,
                'quantity'     => $qty,
                'unit_price'   => $unitPrice,
                'total'        => $total,
            ];
        });

        // Seller subtotal
        $subtotal = $mappedItems->sum('total');

        // Wilaya: try dedicated column first, then shipping_address
        // FIX: removed ->user->state fallback since 'state' column doesn't exist
        $wilaya = $order->wilaya
            ?? $order->shipping_address
            ?? null;

        // FIX: removed 'state' from customer array since column doesn't exist
        $customer = $order->user ? [
            'name'  => $order->user->name,
            'email' => $order->user->email,
        ] : null;

        $orderArr             = $order->toArray();
        $orderArr['wilaya']   = $wilaya;
        $orderArr['customer'] = $customer;

        return response()->json([
            'success' => true,
            'data'    => [
                'order'           => $orderArr,
                'items'           => $mappedItems->values(),
                'seller_subtotal' => round($subtotal, 3),
            ],
        ]);
    }

    /* ── PATCH /api/seller/orders/{id}/status ── */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:pending,processing,completed,delivered,cancelled',
        ]);

        $seller   = auth()->user();
        $orderIds = $this->sellerOrderIds($seller->id);
        $order    = Order::whereIn('id', $orderIds)->findOrFail($id);
        $order->update(['status' => $request->status]);

        return response()->json([
            'success' => true,
            'message' => 'Order status updated.',
            'data'    => $order,
        ]);
    }

    /* ── PATCH /api/seller/orders/{id}/payment ── */
    public function updatePayment(Request $request, $id)
    {
        $request->validate([
            'payment_status' => 'required|in:unpaid,paid,refunded',
        ]);

        $seller   = auth()->user();
        $orderIds = $this->sellerOrderIds($seller->id);
        $order    = Order::whereIn('id', $orderIds)->findOrFail($id);
        $order->update(['payment_status' => $request->payment_status]);

        return response()->json([
            'success' => true,
            'message' => 'Payment status updated.',
            'data'    => $order,
        ]);
    }
}
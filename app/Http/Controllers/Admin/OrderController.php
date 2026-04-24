<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\PlatformUser;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class OrderController extends Controller
{
    /**
     * GET /api/admin/orders
     *
     * Lists all orders with optional filters.
     *
     * NEW FILTER: ?seller_type=platform  → orders containing brand products
     *             ?seller_type=sellers   → orders containing only seller products
     *             (omit for all orders)
     */
    public function index(Request $request)
    {
        $query = Order::with(['user:id,name,email']);

        // ── Existing filters ──────────────────────────────────────────────
        if ($s = $request->query('status')) {
            $query->where('status', $s);
        }
        if ($s = $request->query('search')) {
            $query->where(function ($q) use ($s) {
                $q->where('order_number', 'like', "%$s%")
                  ->orWhereHas('user', fn($q2) =>
                      $q2->where('name', 'like', "%$s%")
                         ->orWhere('email', 'like', "%$s%")
                  );
            });
        }
        if ($d = $request->query('date_from')) {
            $query->whereDate('created_at', '>=', $d);
        }
        if ($d = $request->query('date_to')) {
            $query->whereDate('created_at', '<=', $d);
        }
        if ($m = $request->query('payment_method')) {
            $query->where('payment_method', $m);
        }

        // ── NEW: seller_type filter ────────────────────────────────────────
        // platform → orders that have at least one brand product item
        // sellers  → orders that have NO brand product items
        $sellerType    = $request->query('seller_type');
        $platformUserId = PlatformUser::id();

        if ($sellerType === 'platform' && $platformUserId) {
            // Orders where at least one seller_order belongs to the platform user
            $query->whereHas('sellerOrders', fn($q) =>
                $q->where('seller_id', $platformUserId)
            );
        } elseif ($sellerType === 'sellers' && $platformUserId) {
            // Orders that have NO seller_orders belonging to the platform user
            $query->whereDoesntHave('sellerOrders', fn($q) =>
                $q->where('seller_id', $platformUserId)
            );
        }

        $orders = $query->orderByDesc('created_at')
            ->paginate((int) $request->query('per_page', 15));

        // Annotate each order with has_platform_items flag for the frontend
        if ($platformUserId) {
            $orders->getCollection()->transform(function ($order) use ($platformUserId) {
                $order->has_platform_items = $order->sellerOrders()
                    ->where('seller_id', $platformUserId)
                    ->exists();
                return $order;
            });
        }

        return response()->json(['success' => true, 'data' => $orders]);
    }

    /**
     * GET /api/admin/orders/{id}
     */
    public function show($id)
    {
        $order = Order::with([
            'user:id,name,email',
            'items',
            'items.product:id,name,slug,is_platform_product',
            'items.product.primaryImage',
            'items.variant:id,product_id,sku',
            'items.variant.images',
            'items.variant.attributeOptions.attribute:id,slug,name,type',
            'sellerOrders',
            'sellerOrders.seller:id,name,email',
        ])->findOrFail($id);

        // Annotate items with image URL and variant options
        $order->items->each(function ($item) {
            $item->resolved_image_url = $this->resolveItemImage($item);

            if ($item->variant && $item->variant->relationLoaded('attributeOptions')) {
                $item->variant_options = $item->variant->attributeOptions
                    ->mapWithKeys(fn($o) => [
                        $o->attribute->slug => [
                            'value'     => $o->value,
                            'color_hex' => $o->color_hex,
                        ],
                    ]);
            } else {
                $item->variant_options = [];
            }

            // Flag if this item is a brand product
            $item->is_platform_item = (bool) optional($item->product)->is_platform_product;
        });

        // Flag if order contains any brand product items
        $platformUserId = PlatformUser::id();
        $order->has_platform_items = $platformUserId
            ? $order->sellerOrders->contains('seller_id', $platformUserId)
            : false;

        return response()->json(['success' => true, 'data' => $order]);
    }

    /**
     * PATCH /api/admin/orders/{id}/status
     *
     * Updates status on seller_orders rows for the given order.
     * Admin can target:
     *   - all seller_orders (default)
     *   - only platform seller_order (?scope=platform)
     *   - only third-party seller_orders (?scope=sellers)
     */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|string|in:pending,processing,completed,cancelled,delivered,refunded',
            'scope'  => 'nullable|string|in:all,platform,sellers',
        ]);

        try {
            $scope          = $request->input('scope', 'all');
            $platformUserId = PlatformUser::id();

            $sellerOrderQuery = DB::table('seller_orders')->where('order_id', $id);

            if ($scope === 'platform' && $platformUserId) {
                $sellerOrderQuery->where('seller_id', $platformUserId);
            } elseif ($scope === 'sellers' && $platformUserId) {
                $sellerOrderQuery->where('seller_id', '!=', $platformUserId);
            }
            // 'all' → no additional filter, updates all seller_orders for this order

            $sellerOrderQuery->update([
                'status'     => $request->status,
                'updated_at' => now(),
            ]);

            // Also update the parent order's status (top-level view)
            DB::table('orders')
                ->where('id', $id)
                ->update(['status' => $request->status, 'updated_at' => now()]);

            $order = Order::findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'Status updated.',
                'data'    => $order,
            ]);

        } catch (\Throwable $e) {
            Log::error('[AdminOrder::updateStatus] ' . $e->getMessage(), [
                'order_id' => $id,
                'status'   => $request->status,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to update status: ' . $e->getMessage(),
            ], 500);
        }
    }
    /**
 * PATCH /api/admin/orders/{id}/payment-status
 */
public function updatePaymentStatus(Request $request, $id)
{
    $request->validate([
        'payment_status' => 'required|string|in:unpaid,paid,refunded',
    ]);

    try {
        DB::table('orders')
            ->where('id', $id)
            ->update([
                'payment_status' => $request->payment_status,
                'updated_at'     => now(),
            ]);

        // Cascade to all seller sub-orders
        DB::table('seller_orders')
            ->where('order_id', $id)
            ->update([
                'payment_status' => $request->payment_status,
                'updated_at'     => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Payment status updated.',
            'data'    => Order::findOrFail($id),
        ]);

    } catch (\Throwable $e) {
        Log::error('[AdminOrder::updatePaymentStatus] ' . $e->getMessage(), ['order_id' => $id]);
        return response()->json([
            'success' => false,
            'message' => 'Failed to update payment status: ' . $e->getMessage(),
        ], 500);
    }
}

    /**
     * PATCH /api/admin/orders/{id}/confirm-payment
     */
    public function confirmPayment(Request $request, $id)
    {
        $request->validate([
            'd17_reference' => 'nullable|string|max:100',
        ]);

        try {
            $order = Order::findOrFail($id);

            if (!in_array($order->payment_method, ['cod', 'd17'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only COD and D17 orders require manual payment confirmation.',
                ], 422);
            }

            $updateData = [
                'payment_status' => 'paid',
                'status'         => 'processing',
                'updated_at'     => now(),
            ];

            if ($request->d17_reference) {
                $updateData['d17_reference'] = $request->d17_reference;
            }

            DB::table('orders')->where('id', $id)->update($updateData);

            DB::table('seller_orders')
                ->where('order_id', $id)
                ->update(['payment_status' => 'paid', 'updated_at' => now()]);

            return response()->json([
                'success' => true,
                'message' => 'Payment confirmed.',
                'data'    => Order::findOrFail($id),
            ]);

        } catch (\Throwable $e) {
            Log::error('[AdminOrder::confirmPayment] ' . $e->getMessage(), ['order_id' => $id]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/admin/orders/stats
     */
    public function stats(Request $request)
    {
        $platformUserId = PlatformUser::id();

        $base = Order::query();

        // Platform orders count (orders with brand products)
        $platformOrdersCount = $platformUserId
            ? Order::whereHas('sellerOrders', fn($q) =>
                $q->where('seller_id', $platformUserId)
              )->count()
            : 0;

        return response()->json(['success' => true, 'data' => [
            'total'           => Order::count(),
            'pending'         => (clone $base)->where('status', 'pending')->count(),
            'processing'      => (clone $base)->where('status', 'processing')->count(),
            'completed'       => (clone $base)->where('status', 'completed')->count(),
            'delivered'       => (clone $base)->where('status', 'delivered')->count(),
            'cancelled'       => (clone $base)->where('status', 'cancelled')->count(),
            'revenue'         => (clone $base)->where('payment_status', 'paid')->sum('total_amount'),
            'platform_orders' => $platformOrdersCount,
        ]]);
    }

    // ── Private ────────────────────────────────────────────────────────────

    private function resolveItemImage($item): ?string
    {
        if (!empty($item->image_url)) {
            return str_starts_with($item->image_url, 'http') ? $item->image_url : url($item->image_url);
        }
        if ($item->variant && $item->variant->images->isNotEmpty()) {
            return Storage::url($item->variant->images->first()->image_path);
        }
        if ($item->product && $item->product->primaryImage) {
            return Storage::url($item->product->primaryImage->image_path);
        }
        return null;
    }
}
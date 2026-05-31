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
     */
    public function index(Request $request)
    {
        $query = Order::with(['user:id,name,email']);

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

        $sellerType     = $request->query('seller_type');
        $platformUserId = PlatformUser::id();

        if ($sellerType === 'platform' && $platformUserId) {
            $query->whereHas('sellerOrders', fn($q) =>
                $q->where('seller_id', $platformUserId)
            );
        } elseif ($sellerType === 'sellers' && $platformUserId) {
            $query->whereDoesntHave('sellerOrders', fn($q) =>
                $q->where('seller_id', $platformUserId)
            );
        }

        $orders = $query->orderByDesc('created_at')
            ->paginate((int) $request->query('per_page', 15));

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

    // ── Step 1: Resolve image, variant_options, is_platform_item ──────────
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

        $item->is_platform_item = (bool) optional($item->product)->is_platform_product;
    });

    // ── Step 2: Detect returned / exchanged items ──────────────────────────
    $returnedItemIds   = collect();
    $exchangedItemIds  = collect();
    $allItemsReturned  = false;
    $allItemsExchanged = false;

    $complaints = \App\Models\Complaint::where('order_id', $id)
        ->where('status', \App\Models\Complaint::STATUS_APPROVED)
        ->where('refund_status', \App\Models\Complaint::REFUND_STATUS_COMPLETED)
        ->get(['id', 'order_item_ids', 'resolution_type']);

    foreach ($complaints as $complaint) {
        $ids        = $complaint->order_item_ids;
        $isExchange = $complaint->resolution_type === \App\Models\Complaint::RESOLUTION_EXCHANGE;

        if (is_null($ids) || empty($ids)) {
            if ($isExchange) { $allItemsExchanged = true; }
            else             { $allItemsReturned  = true; }
            continue;
        }
        if ($isExchange) {
            $exchangedItemIds = $exchangedItemIds->merge($ids);
        } else {
            $returnedItemIds = $returnedItemIds->merge($ids);
        }
    }

    $returnedItemIds  = $returnedItemIds->unique()->toArray();
    $exchangedItemIds = $exchangedItemIds->unique()->toArray();

    // ── Step 3: Tag each item with item_status ─────────────────────────────
    $order->items->each(function ($item) use (
        $returnedItemIds, $allItemsReturned,
        $exchangedItemIds, $allItemsExchanged
    ) {
        $isReturned  = $allItemsReturned  || in_array($item->id, $returnedItemIds);
        $isExchanged = $allItemsExchanged || in_array($item->id, $exchangedItemIds);
        $item->item_status = $isReturned ? 'returned' : ($isExchanged ? 'exchanged' : null);
        $item->is_returned = $isReturned;
    });

    // ── Step 4: Commission summary (exclude returned items) ────────────────
    $nonReturnedItems = $order->items->filter(fn($i) => $i->item_status !== 'returned');

    $commissionSummary = [
        'gross_total'      => round($nonReturnedItems->sum('total'),             3),
        'total_commission' => round($nonReturnedItems->sum('commission_amount'), 3),
        'total_seller'     => round($nonReturnedItems->sum('seller_amount'),     3),
    ];
    $order->setAttribute('commission_summary', $commissionSummary);

    // ── Step 5: Fix total_amount (active subtotals + shipping) ────────────
    $activeSubtotal = $order->sellerOrders
        ->where('status', '!=', 'cancelled')
        ->sum(fn($so) => (float) $so->subtotal);

    $order->total_amount = round(
        $activeSubtotal + (float) ($order->shipping_fee ?? 0),
        3
    );

    // ── Step 6: Platform badge ─────────────────────────────────────────────
    $platformUserId = PlatformUser::id();
    $order->has_platform_items = $platformUserId
        ? $order->sellerOrders->contains('seller_id', $platformUserId)
        : false;

    return response()->json(['success' => true, 'data' => $order]);
}
    /**
     * PATCH /api/admin/orders/{id}/status
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

            $sellerOrderQuery->update([
                'status'     => $request->status,
                'updated_at' => now(),
            ]);

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
     *
     * UPDATED: 'revenue' is now platform commission from paid orders only.
     * Added 'platform_commission' and 'seller_payouts' for the admin dashboard.
     */
    public function stats(Request $request)
    {
        $platformUserId = PlatformUser::id();
        $base           = Order::query();

        $platformOrdersCount = $platformUserId
            ? Order::whereHas('sellerOrders', fn($q) =>
                $q->where('seller_id', $platformUserId)
              )->count()
            : 0;

        // ── Check if commission columns exist ─────────────────────────────────
        try {
            $itemCols      = DB::select("SHOW COLUMNS FROM order_items");
            $itemColNames  = array_map(fn($c) => $c->Field, $itemCols);
            $hasCommission = in_array('commission_amount', $itemColNames);
        } catch (\Exception $e) {
            $hasCommission = false;
        }

        // ── Platform commission = what admin earns from paid orders ───────────
        $platformCommission = 0;
        $sellerPayouts      = 0;

        if ($hasCommission) {
            try {
                $platformCommission = DB::table('order_items as oi')
                    ->join('orders as o', 'o.id', '=', 'oi.order_id')
                    ->where('o.payment_status', 'paid')
                    ->sum('oi.commission_amount');

                $sellerPayouts = DB::table('order_items as oi')
                    ->join('orders as o', 'o.id', '=', 'oi.order_id')
                    ->where('o.payment_status', 'paid')
                    ->sum('oi.seller_amount');
            } catch (\Exception $e) {
                $platformCommission = 0;
                $sellerPayouts      = 0;
            }
        }

        // Gross revenue (full order totals for paid orders — kept for reference)
        $grossRevenue = (clone $base)->where('payment_status', 'paid')->sum('total_amount');

        return response()->json(['success' => true, 'data' => [
            'total'               => Order::count(),
            'pending'             => (clone $base)->where('status', 'pending')->count(),
            'processing'          => (clone $base)->where('status', 'processing')->count(),
            'completed'           => (clone $base)->where('status', 'completed')->count(),
            'delivered'           => (clone $base)->where('status', 'delivered')->count(),
            'cancelled'           => (clone $base)->where('status', 'cancelled')->count(),
            // ── Revenue split ─────────────────────────────────────────────────
            'revenue'             => round((float) $platformCommission, 3), // ← admin's actual earnings
            'gross_revenue'       => round((float) $grossRevenue, 3),       // ← full order totals (for reference)
            'platform_commission' => round((float) $platformCommission, 3), // ← same as revenue, explicit
            'seller_payouts'      => round((float) $sellerPayouts, 3),      // ← what sellers receive
            // ── Other ─────────────────────────────────────────────────────────
            'platform_orders'     => $platformOrdersCount,
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
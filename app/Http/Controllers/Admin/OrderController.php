<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\PlatformUser;
use App\Exceptions\InsufficientStock;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\OrderExportResource;
use App\Http\Resources\Admin\SellerPickupResource;
use App\Http\Resources\Admin\ShippingAddressResource;
use App\Models\Order;
use App\Models\OrderExport;
use App\Models\SellerApplication;
use App\Services\Orders\DeliveryDocumentService;
use App\Services\Orders\OrderStock;
use App\Services\Orders\SellerOrderNotifier;
use App\Support\SellerPickup;
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
        $query = Order::with([
            'user:id,name,email',
            'sellerOrders:id,order_id,seller_id,status,subtotal,coupon_code,discount_amount',
            'sellerOrders.seller:id,name',
            'sellerOrders.seller.sellerApplication', // pickup completeness → export readiness
        ])
            ->withMax(['exports as slips_exported_at' => fn($q) => $q->whereIn('type', OrderExport::SLIP_TYPES)], 'created_at');

        // Confirmed orders whose slips were never exported: the courier hand-off queue.
        if ($request->boolean('needs_slips')) {
            $query->where('status', 'confirmed')
                  ->whereDoesntHave('exports', fn($q) => $q->whereIn('type', OrderExport::SLIP_TYPES));
        }

        if ($s = $request->query('status')) {
            $query->where('status', $s);
        }
        if ($s = $request->query('search')) {
            $query->where(function ($q) use ($s) {
                $q->where('order_number', 'like', "%$s%")
                  ->orWhere('recipient_name', 'like', "%$s%")
                  ->orWhere('phone', 'like', "%$s%")
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

        $documents = app(DeliveryDocumentService::class);

        $orders->getCollection()->transform(function ($order) use ($platformUserId, $documents) {
            // Same figure as the detail drawer: what the customer pays
            $money = $order->moneySummary();
            $order->subtotal        = $money['subtotal'];
            $order->discount_amount = $money['discount_amount'];
            $order->coupon_codes    = $money['coupon_codes'];
            $order->total_amount    = $money['total'];

            $order->has_platform_items = $platformUserId
                ? $order->sellerOrders->contains('seller_id', $platformUserId)
                : false;

            // Lets the list disable bulk export with a reason, without opening each order.
            $order->setAttribute('address_status', ShippingAddressResource::make($order)->resolve()['status']);
            $order->setAttribute('export_issues', $documents->exportIssues($order));

            $order->unsetRelation('sellerOrders');
            return $order;
        });

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
            'items.variant.attributeOptions.attribute:id,slug,name,name_fr,name_ar,type',
            'sellerOrders' => fn($q) => $q->orderBy('id'),
            'sellerOrders.seller:id,name,email',
            'sellerOrders.seller.sellerApplication',
            'exports' => fn($q) => $q->latest('created_at'),
            'exports.exporter:id,name',
        ])->findOrFail($id);

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

        $order->items->each(function ($item) use (
            $returnedItemIds, $allItemsReturned,
            $exchangedItemIds, $allItemsExchanged
        ) {
            $isReturned  = $allItemsReturned  || in_array($item->id, $returnedItemIds);
            $isExchanged = $allItemsExchanged || in_array($item->id, $exchangedItemIds);
            $item->item_status = $isReturned ? 'returned' : ($isExchanged ? 'exchanged' : null);
            $item->is_returned = $isReturned;
        });

        $nonReturnedItems = $order->items->filter(fn($i) => $i->item_status !== 'returned');

        // Revenue split on item prices AFTER the seller's coupon (commission base).
        // gross_total = items before discount, net_total = what the customer paid for items.
        $commissionSummary = [
            'gross_total'      => round($nonReturnedItems->sum('total'),                                  3),
            'total_discount'   => round($nonReturnedItems->sum(fn($i) => (float) $i->discount_amount),    3),
            'net_total'        => round($nonReturnedItems->sum(fn($i) => (float) ($i->net_total ?? $i->total)), 3),
            'total_commission' => round($nonReturnedItems->sum('commission_amount'), 3),
            'total_seller'     => round($nonReturnedItems->sum('seller_amount'),     3),
        ];

        // Shipping: the agency always bills the order; on free-shipping orders
        // the seller pays it out of their earnings.
        $activeSellerOrders = $order->sellerOrders->where('status', '!=', 'cancelled');
        $sellerShipping     = round($activeSellerOrders->sum(fn($so) => (float) ($so->seller_shipping_charge ?? 0)), 3);
        $commissionSummary += [
            'shipping_cost'    => $order->getAttribute('shipping_cost') !== null ? round((float) $order->getAttribute('shipping_cost'), 3) : null,
            'shipping_paid_by' => $order->getAttribute('shipping_paid_by'),
            'seller_shipping'  => $sellerShipping,
            'total_seller_net' => round($commissionSummary['total_seller'] - $sellerShipping, 3),
        ];
        $order->setAttribute('commission_summary', $commissionSummary);

        // subtotal − discount + shipping, live from non-cancelled seller_orders
        $money = $order->moneySummary();
        $order->subtotal        = $money['subtotal'];
        $order->discount_amount = $money['discount_amount'];
        $order->coupon_codes    = $money['coupon_codes'];
        $order->shipping_fee    = $money['shipping_fee'];
        $order->total_amount    = $money['total'];

        $platformUserId = PlatformUser::id();
        $order->has_platform_items = $platformUserId
            ? $order->sellerOrders->contains('seller_id', $platformUserId)
            : false;

        $this->attachDeliveryData($order);

        return response()->json(['success' => true, 'data' => $order]);
    }

    /**
     * Shipping address card, per-sub-order pickup + slip money, export
     * readiness and history for the order drawer. Admin-only data.
     */
    private function attachDeliveryData(Order $order): void
    {
        $documents = app(DeliveryDocumentService::class);
        $activeIds = $documents->activeSellerOrders($order)->pluck('id')->all();

        $order->setAttribute('shipping_address', ShippingAddressResource::make($order)->resolve());

        foreach ($order->sellerOrders as $so) {
            $pickup     = SellerPickup::for($so->seller, $so->seller_id);
            $shippable  = in_array($so->id, $activeIds, true);
            $so->setAttribute('pickup', SellerPickupResource::make($pickup)->resolve());
            $so->setAttribute('reference', $documents->reference($order, $so));
            $so->setAttribute('is_shippable', $shippable);
            $so->setAttribute('slip_money', $shippable ? $documents->money($order, $so) : null);
            $so->seller?->unsetRelation('sellerApplication'); // pickup above is all the drawer needs
        }

        $issues = $documents->exportIssues($order);
        $order->setAttribute('export_readiness', ['ready' => $issues === [], 'issues' => $issues]);
        $order->setAttribute('export_history', OrderExportResource::collection($order->exports)->resolve());
        $order->setAttribute('slips_exported_at', optional($order->exports->whereIn('type', OrderExport::SLIP_TYPES)->first()?->created_at)->toIso8601String());
        $order->unsetRelation('exports');
    }

    /**
     * PUT /api/admin/sellers/{sellerId}/pickup-address
     *
     * Lets the admin complete a seller's pickup address (typically after a
     * phone call) so slips can be exported. Touches only the pickup fields of
     * the seller's latest application — never its review status.
     */
    public function updateSellerPickup(Request $request, int $sellerId)
    {
        $application = SellerApplication::where('user_id', $sellerId)->latest()->first();
        if (!$application) {
            return response()->json([
                'success' => false,
                'message' => $sellerId === PlatformUser::id()
                    ? "CHOOSE'Tounsi's own pickup address is set in the server .env (PLATFORM_PICKUP_*)."
                    : 'This seller has no seller profile to update.',
            ], 422);
        }

        SellerPickup::prepare($request);
        $application->update($request->validate(SellerPickup::rules()));

        Log::info('[AdminOrder::updateSellerPickup] seller ' . $sellerId . ' pickup updated by admin ' . $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Pickup address saved.',
            'data'    => SellerPickupResource::make(SellerPickup::for(null, $sellerId))->resolve(),
        ]);
    }

    /**
     * PATCH /api/admin/orders/{id}/status
     */
    /**
     * A D17 / card payment just recorded as paid: the sellers can now hear
     * about the order. (COD "paid" is cash collected at delivery — those
     * sellers were told at checkout.)
     */
    private function notifyOnlinePayment(int $orderId): void
    {
        $order = Order::find($orderId);
        if ($order && in_array($order->payment_method, SellerOrderNotifier::ONLINE_METHODS, true)) {
            app(SellerOrderNotifier::class)->orderPlaced($order);
        }
    }

    /**
     * Cancelled seller orders written with DB::table skip the observer: their
     * stock and flash units go back here (each line only once — OrderStock).
     */
    private function releaseCancelled(int $orderId): void
    {
        $cancelledIds = DB::table('seller_orders')->where('order_id', $orderId)->where('status', 'cancelled')->pluck('id')->all();
        app(OrderStock::class)->releaseForSellerOrders($cancelledIds);
        app(\App\Services\PromotionService::class)->releaseForSellerOrders($cancelledIds);
    }

    private function insufficientStock(InsufficientStock $e)
    {
        return response()->json([
            'success' => false,
            'message' => "Not enough stock to re-open this order: {$e->label} has {$e->available} left.",
        ], 422);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|string|in:pending,confirmed,completed,cancelled,delivered,refunded,out_for_delivery',
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

            $order = Order::findOrFail($id);

            DB::transaction(function () use ($request, $id, $order, $sellerOrderQuery) {
                Order::whereKey($id)->lockForUpdate()->first();
                $previous    = (clone $sellerOrderQuery)->lockForUpdate()->pluck('status', 'id')->all();
                $affectedIds = array_keys($previous);

                // Cancelled → anything else: the lines take their stock back first
                if ($request->status !== 'cancelled') {
                    app(OrderStock::class)->reclaimForSellerOrders(
                        array_keys(array_filter($previous, fn ($s) => $s === 'cancelled'))
                    );
                }

                $sellerOrderQuery->update([
                    'status'     => $request->status,
                    'updated_at' => now(),
                ]);

                DB::table('orders')
                    ->where('id', $id)
                    ->update(['status' => $request->status, 'updated_at' => now()]);

                // One e-mail + bell entry per affected seller sub-order (after commit, never twice)
                if ($request->status === 'cancelled') {
                    $this->releaseCancelled((int) $id);
                    app(SellerOrderNotifier::class)->orderCancelled($order, $affectedIds);
                } elseif ($request->status === 'confirmed') {
                    app(SellerOrderNotifier::class)->orderConfirmed($order, $affectedIds);
                }
            });

            $order->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Status updated.',
                'data'    => $order,
            ]);

        } catch (InsufficientStock $e) {
            return $this->insufficientStock($e);
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
     * PATCH /api/admin/orders/{id}/confirm-order
     *
     * Admin calls the client, writes a note, then confirms or cancels.
     * pending → confirmed  (or cancelled)
     */
    /**
 * PATCH /api/admin/orders/{id}/confirm-order
 */
public function confirmOrder(Request $request, $id)
{
    $request->validate([
        'action'     => 'required|in:confirmed,cancelled',
        'admin_note' => 'nullable|string|max:1000',
    ]);

    try {
        $order = Order::findOrFail($id);

        if (!in_array($order->status, ['pending'])) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending orders can be confirmed or cancelled via this endpoint.',
            ], 422);
        }

        $newStatus  = $request->action;
        $updateData = [
            'status'     => $newStatus,
            'admin_note' => $request->admin_note ?? $order->admin_note,
            'updated_at' => now(),
        ];

        if ($newStatus === 'confirmed') {
            $updateData['confirmed_at'] = now();
        }

        $applied = DB::transaction(function () use ($id, $order, $newStatus, $updateData) {
            // Re-check under lock: a double click / second admin must not cancel twice
            if (Order::whereKey($id)->lockForUpdate()->value('status') !== 'pending') {
                return false;
            }
            DB::table('seller_orders')->where('order_id', $id)->lockForUpdate()->get(['id']);

            DB::table('orders')->where('id', $id)->update($updateData);

            // Cascade to all seller sub-orders
            DB::table('seller_orders')
                ->where('order_id', $id)
                ->update(['status' => $newStatus, 'updated_at' => now()]);

            // Each seller gets one e-mail + bell entry for their sub-order
            // (sent after the commit, never twice).
            if ($newStatus === 'cancelled') {
                $this->releaseCancelled((int) $id);
                app(SellerOrderNotifier::class)->orderCancelled($order);
            } else {
                app(SellerOrderNotifier::class)->orderConfirmed($order);
            }
            return true;
        });

        if (!$applied) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending orders can be confirmed or cancelled via this endpoint.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $newStatus === 'confirmed'
                ? 'Order confirmed. Sellers have been notified.'
                : 'Order cancelled.',
            'data'    => Order::findOrFail($id),
        ]);

    } catch (\Throwable $e) {
        Log::error('[AdminOrder::confirmOrder] ' . $e->getMessage(), ['order_id' => $id]);
        return response()->json([
            'success' => false,
            'message' => 'Failed: ' . $e->getMessage(),
        ], 500);
    }
}
    /**
     * PATCH /api/admin/orders/{id}/note
     *
     * Save or update the admin note without changing status.
     */
    public function saveNote(Request $request, $id)
    {
        $request->validate([
            'admin_note' => 'required|string|max:1000',
        ]);

        try {
            DB::table('orders')
                ->where('id', $id)
                ->update(['admin_note' => $request->admin_note, 'updated_at' => now()]);

            return response()->json([
                'success' => true,
                'message' => 'Note saved.',
                'data'    => Order::findOrFail($id),
            ]);

        } catch (\Throwable $e) {
            Log::error('[AdminOrder::saveNote] ' . $e->getMessage(), ['order_id' => $id]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
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

            if ($request->payment_status === 'paid') {
                $this->notifyOnlinePayment((int) $id);
            }

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
                'updated_at'     => now(),
            ];

            if ($request->d17_reference) {
                $updateData['d17_reference'] = $request->d17_reference;
            }

            DB::table('orders')->where('id', $id)->update($updateData);

            DB::table('seller_orders')
                ->where('order_id', $id)
                ->update(['payment_status' => 'paid', 'updated_at' => now()]);

            $this->notifyOnlinePayment((int) $id);

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
        $base           = Order::query();

        $platformOrdersCount = $platformUserId
            ? Order::whereHas('sellerOrders', fn($q) =>
                $q->where('seller_id', $platformUserId)
              )->count()
            : 0;

        try {
            $itemCols      = DB::select("SHOW COLUMNS FROM order_items");
            $itemColNames  = array_map(fn($c) => $c->Field, $itemCols);
            $hasCommission = in_array('commission_amount', $itemColNames);
        } catch (\Exception $e) {
            $hasCommission = false;
        }

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

        $grossRevenue = (clone $base)->where('payment_status', 'paid')->sum('total_amount');

        return response()->json(['success' => true, 'data' => [
            'total'               => Order::count(),
            'pending'             => (clone $base)->where('status', 'pending')->count(),
            'confirmed'           => (clone $base)->where('status', 'confirmed')->count(),
            'completed'           => (clone $base)->where('status', 'completed')->count(),
            'delivered'           => (clone $base)->where('status', 'delivered')->count(),
            'cancelled'           => (clone $base)->where('status', 'cancelled')->count(),
            'out_for_delivery'    => (clone $base)->where('status', 'out_for_delivery')->count(),
            'revenue'             => round((float) $platformCommission, 3),
            'gross_revenue'       => round((float) $grossRevenue, 3),
            'platform_commission' => round((float) $platformCommission, 3),
            'seller_payouts'      => round((float) $sellerPayouts, 3),
            'platform_orders'     => $platformOrdersCount,
        ]]);
    }

    // ── Private ────────────────────────────────────────────────────────────

    private function resolveItemImage($item): ?string
    {
        if (!empty($item->image_url)) {
            return str_starts_with($item->image_url, 'http') ? $item->image_url : url($item->image_url);
        }
        // Main image of the variant's color group, else the product cover
        return $item->product ? \App\Services\ProductImages::thumbnailFor($item->product, $item->variant) : null;
    }
}
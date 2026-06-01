<?php

namespace App\Http\Controllers\Api\Delivery;

use App\Http\Controllers\Controller;
use App\Models\DeliveryAssignment;
use App\Models\Order;
use App\Models\SellerOrder;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * FILE: app/Http/Controllers/Api/Delivery/DeliveryController.php  ← REPLACE
 *
 * FIXES in this version:
 *
 *   BUG 1 — Client couldn't file complaint after delivery guy marked "delivered":
 *     Root cause: updateStatus() was updating seller_orders.status → 'delivered'
 *     but never touching orders.status. The complaint eligibility check reads
 *     orders.status === 'delivered', so it always failed for delivery-completed orders.
 *     Fix: syncParentOrderStatus() is now called inside updateStatus() after
 *     updating the seller_order, identical to SellerOrderController's approach.
 *
 *   BUG 2 — "Picked up" status was invisible everywhere:
 *     Root cause: picked_up mapped to 'processing' on seller_orders, which was
 *     already the status — nothing changed visually.
 *     Fix: picked_up now maps to 'out_for_delivery' (new ENUM value added via
 *     migration 2026_05_24_000004). This gives a distinct tracking status
 *     visible in client storefront, seller dashboard, and admin panel.
 *
 *   All existing methods are preserved unchanged.
 */
class DeliveryController extends Controller
{
    // ══════════════════════════════════════════════════════════════════════
    // DELIVERY ADMIN ENDPOINTS  (all unchanged)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * GET /api/delivery/orders
     */
    public function readyOrders(Request $request)
{
    $orders = SellerOrder::where('status', 'completed')
        ->whereDoesntHave('deliveryAssignment')

            ->with([
                'order.user:id,name,email',
                'items.product:id,name',
                'seller:id,name,email',
                'seller.sellerApplication:id,user_id,phone_number,wilaya,city,business_name',
            ])
            ->latest()
            ->paginate((int) $request->query('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => $orders->through(fn($so) => $this->formatOrder($so)),
        ]);
    }

    /**
     * GET /api/delivery/orders/active
     */
    public function activeOrders(Request $request)
    {
        $orders = SellerOrder::whereHas('deliveryAssignment', function ($q) {
                $q->whereIn('status', ['assigned', 'picked_up']);
            })
            ->with([
                'order.user:id,name,email',
                'items.product:id,name',
                'seller:id,name,email',
                'seller.sellerApplication:id,user_id,phone_number,wilaya,city,business_name',
                'deliveryAssignment.deliveryGuy:id,name,email',
            ])
            ->latest()
            ->paginate((int) $request->query('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => $orders->through(fn($so) => $this->formatOrder($so, withAssignment: true)),
        ]);
    }

    /**
     * GET /api/delivery/stats
     */
    public function stats()
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'ready_for_pickup' => SellerOrder::where('status', 'completed')
                    ->whereDoesntHave('deliveryAssignment')->count(),
                'assigned'         => DeliveryAssignment::where('status', 'assigned')->count(),
                'picked_up'        => DeliveryAssignment::where('status', 'picked_up')->count(),
                'delivered_today'  => DeliveryAssignment::where('status', 'delivered')
                    ->whereDate('delivered_at', today())->count(),
                'canceled_today'   => DeliveryAssignment::where('status', 'canceled')
                    ->whereDate('updated_at', today())->count(),
                'team_count'       => User::where('role', 'delivery_guy')
                    ->where('is_active', true)->count(),
            ],
        ]);
    }

    /**
     * POST /api/delivery/orders/{id}/assign
     */
    public function assign(Request $request, int $id)
    {
        $request->validate([
            'delivery_guy_id' => 'required|integer|exists:users,id',
            'notes'           => 'nullable|string|max:500',
        ]);

        $sellerOrder = SellerOrder::findOrFail($id);

if ($sellerOrder->status !== 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Only orders with status "completed" can be assigned.',
            ], 422);
        }

        $deliveryGuy = User::where('id', $request->delivery_guy_id)
            ->where('role', 'delivery_guy')
            ->where('is_active', true)
            ->first();

        if (!$deliveryGuy) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid delivery guy or account is inactive.',
            ], 422);
        }

        try {
            $assignment = DeliveryAssignment::updateOrCreate(
                ['seller_order_id' => $sellerOrder->id],
                [
                    'delivery_guy_id' => $deliveryGuy->id,
                    'assigned_by'     => auth()->id(),
                    'status'          => 'assigned',
                    'assigned_at'     => now(),
                    'picked_up_at'    => null,
                    'delivered_at'    => null,
                    'notes'           => $request->notes,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => "Order assigned to {$deliveryGuy->name}.",
                'data'    => $assignment->load('deliveryGuy:id,name,email'),
            ]);

        } catch (\Throwable $e) {
            Log::error('[Delivery::assign] ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to assign order.'], 500);
        }
    }

    /**
     * GET /api/delivery/team
     */
    public function team()
    {
        $guys = User::where('role', 'delivery_guy')
            ->where('is_active', true)
            ->select('id', 'name', 'email')
            ->withCount([
                'deliveryAssignments as active_orders' => fn($q) =>
                    $q->whereIn('status', ['assigned', 'picked_up']),
            ])
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => $guys]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // DELIVERY GUY ENDPOINTS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * GET /api/delivery/my-orders
     */
    public function myOrders(Request $request)
    {
        $deliveryGuyId = auth()->id();

        $orders = SellerOrder::whereHas('deliveryAssignment', function ($q) use ($deliveryGuyId) {
                $q->where('delivery_guy_id', $deliveryGuyId)
                  ->whereNotIn('status', ['delivered', 'canceled']);
            })
            ->with([
                'order.user:id,name,email',
                'order'       => fn($q) => $q->select('id', 'order_number', 'wilaya', 'address', 'phone', 'notes', 'user_id'),
                'items.product:id,name',
                'seller:id,name,email',
                'seller.sellerApplication:id,user_id,phone_number,wilaya,city,business_name',
                'deliveryAssignment',
            ])
            ->latest()
            ->paginate((int) $request->query('per_page', 20));

        return response()->json([
            'success' => true,
            'data'    => $orders->through(fn($so) => $this->formatOrder($so, withAssignment: true)),
        ]);
    }

    /**
     * PUT /api/delivery/orders/{id}/status
     *
     * FIXES APPLIED:
     *
     *   1. picked_up → 'out_for_delivery' (was: 'processing')
     *      This makes the "in transit" state visible to client, seller, admin.
     *
     *   2. After updating seller_order status, syncParentOrderStatus() is called.
     *      This ensures orders.status is always kept in sync — which is required
     *      for the complaint eligibility check to work after delivery.
     *
     *   3. Review prompts are created when delivery guy confirms 'delivered',
     *      identical to SellerOrderController's behaviour when seller marks delivered.
     */
    public function updateStatus(Request $request, int $id)
    {
        $request->validate([
            'status' => 'required|in:picked_up,delivered,canceled',
        ]);

        $assignment = DeliveryAssignment::where('seller_order_id', $id)
            ->where('delivery_guy_id', auth()->id())
            ->firstOrFail();

        $newStatus = $request->status;

        $validTransitions = [
            'assigned'  => ['picked_up', 'canceled'],
            'picked_up' => ['delivered', 'canceled'],
        ];

        if (!in_array($newStatus, $validTransitions[$assignment->status] ?? [])) {
            return response()->json([
                'success' => false,
                'message' => "Cannot transition from \"{$assignment->status}\" to \"{$newStatus}\".",
            ], 422);
        }

        try {
            // ── 1. Update delivery_assignment timestamps ────────────────────
            $update = ['status' => $newStatus];
            if ($newStatus === 'picked_up') $update['picked_up_at'] = now();
            if ($newStatus === 'delivered')  $update['delivered_at'] = now();
            $assignment->update($update);

            // ── 2. Map assignment status → seller_order status ─────────────
            //
            // CHANGE: picked_up now maps to 'out_for_delivery' instead of
            // 'processing'. This exposes the in-transit state everywhere.
            //
            // OLD: 'picked_up' => 'processing'  ← was invisible (same as before)
            // NEW: 'picked_up' => 'out_for_delivery' ← distinct, trackable step
            //
            $sellerOrderStatus = match ($newStatus) {
                'picked_up' => 'out_for_delivery', // ← FIX (was: 'processing')
                'delivered' => 'delivered',
                'canceled'  => 'completed',         // revert to seller's last known good
                default     => 'processing',
            };

            $sellerOrderUpdate = ['status' => $sellerOrderStatus];

            // Stamp the financial confirmation timestamp on delivery
            if ($newStatus === 'delivered') {
                $sellerOrderUpdate['delivery_confirmed_at'] = now();
            }

            $assignment->sellerOrder->update($sellerOrderUpdate);

            // ── 3. Sync the parent orders.status ──────────────────────────
            //
            // FIX (Bug 1): This was missing. Without it, orders.status never
            // reached 'delivered', so the complaint eligibility check always
            // failed for delivery-completed orders.
            //
            $this->syncParentOrderStatus($assignment->sellerOrder->order_id);

            // ── 4. Create review prompts when order is delivered ───────────
            // Mirrors SellerOrderController::createReviewPrompts()
            if ($newStatus === 'delivered') {
                $this->createReviewPrompts($assignment->sellerOrder);
            }

            return response()->json([
                'success' => true,
                'message' => "Status updated to \"{$newStatus}\".",
                'data'    => $assignment->fresh()->load('sellerOrder'),
            ]);

        } catch (\Throwable $e) {
            Log::error('[Delivery::updateStatus] ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to update status.'], 500);
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // SHARED
    // ══════════════════════════════════════════════════════════════════════

    /**
     * GET /api/delivery/orders/{id}
     */
    public function showOrder(int $id)
    {
        $sellerOrder = SellerOrder::with([
            'order.user:id,name,email',
            'order'  => fn($q) => $q->select('id', 'order_number', 'wilaya', 'address', 'phone', 'notes', 'user_id', 'payment_method'),
            'items.product:id,name',
            'seller:id,name,email',
            'seller.sellerApplication:id,user_id,phone_number,wilaya,city,business_name',
            'deliveryAssignment.deliveryGuy:id,name,email',
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $this->formatOrder($sellerOrder, withAssignment: true),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Derive and write the correct aggregate status to orders.status
     * based on the current state of all seller_orders for that order.
     *
     * Priority cascade (highest → lowest):
     *   all cancelled           → cancelled
     *   all delivered           → delivered
     *   any out_for_delivery    → out_for_delivery
     *   all completed/delivered → completed
     *   default                 → processing
     *
     * Identical logic to SellerOrderController::syncParentOrderStatus().
     */
    private function syncParentOrderStatus(int $orderId): void
    {
        $statuses = SellerOrder::where('order_id', $orderId)
            ->pluck('status')
            ->toArray();

        if (empty($statuses)) return;

        $unique = array_unique($statuses);

        $derived = match (true) {
            $unique === ['cancelled']
                => 'cancelled',
            $unique === ['delivered']
                => 'delivered',
            in_array('out_for_delivery', $statuses)
                => 'out_for_delivery',
            count(array_diff($unique, ['completed', 'delivered'])) === 0
                => 'completed',
            default
                => 'pending',
        };

        Order::where('id', $orderId)->update(['status' => $derived]);
    }

    /**
     * Create review prompts when a delivery guy confirms delivery.
     * Mirrors SellerOrderController::createReviewPrompts() exactly.
     */
    private function createReviewPrompts(SellerOrder $sellerOrder): void
    {
        try {
            $sellerOrder->loadMissing('order');
            $userId = $sellerOrder->order?->user_id;
            if (!$userId) return;

            $items = $sellerOrder->items()->get(['id', 'product_id']);

            foreach ($items as $item) {
                if (!$item->product_id) continue;
                \App\Models\ReviewPrompt::firstOrCreate(
                    ['user_id' => $userId, 'order_item_id' => $item->id],
                    ['product_id' => $item->product_id, 'sent_at' => now(), 'channel' => 'popup']
                );
            }
        } catch (\Exception $e) {
            Log::error('[Delivery::createReviewPrompts] ' . $e->getMessage(), [
                'seller_order_id' => $sellerOrder->id,
            ]);
        }
    }

    /**
     * Format a SellerOrder for the delivery app API response.
     */
    private function formatOrder(SellerOrder $so, bool $withAssignment = false): array
    {
        $order       = $so->order;
        $seller      = $so->relationLoaded('seller') ? $so->seller : null;
        $application = $seller?->relationLoaded('sellerApplication')
            ? $seller->sellerApplication
            : null;

        $data = [
            'id'             => $so->id,
            'order_number'   => $order?->order_number,
            'status'         => $so->status,
            'payment_method' => $order?->payment_method,
            'subtotal'       => (float) $so->subtotal,
            'created_at'     => $so->created_at,
            'wilaya'         => $order?->wilaya,
            'address'        => $order?->address,
            'phone'          => $order?->phone,
            'notes'          => $order?->notes,
            'customer'       => $order?->user ? [
                'id'    => $order->user->id,
                'name'  => $order->user->name,
                'email' => $order->user->email,
            ] : null,
            'seller'         => $seller ? [
                'id'            => $seller->id,
                'name'          => $seller->name,
                'email'         => $seller->email,
                'phone'         => $application?->phone_number,
                'wilaya'        => $application?->wilaya,
                'city'          => $application?->city,
                'business_name' => $application?->business_name,
            ] : null,
            'items' => $so->relationLoaded('items')
                ? $so->items->map(fn($i) => [
                    'id'           => $i->id,
                    'product_name' => $i->product_name ?? $i->product?->name,
                    'quantity'     => (int) $i->quantity,
                    'unit_price'   => (float) $i->unit_price,
                    'total'        => (float) $i->total,
                ])->values()
                : [],
        ];

        if ($withAssignment && $so->relationLoaded('deliveryAssignment') && $so->deliveryAssignment) {
            $da = $so->deliveryAssignment;
            $data['assignment'] = [
                'id'           => $da->id,
                'status'       => $da->status,
                'assigned_at'  => $da->assigned_at,
                'picked_up_at' => $da->picked_up_at,
                'delivered_at' => $da->delivered_at,
                'notes'        => $da->notes,
                'delivery_guy' => $da->relationLoaded('deliveryGuy') ? [
                    'id'    => $da->deliveryGuy->id,
                    'name'  => $da->deliveryGuy->name,
                    'email' => $da->deliveryGuy->email,
                ] : null,
            ];
        }

        return $data;
    }
}
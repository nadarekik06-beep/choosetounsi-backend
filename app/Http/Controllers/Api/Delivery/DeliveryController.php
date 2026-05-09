<?php

namespace App\Http\Controllers\Api\Delivery;

use App\Http\Controllers\Controller;
use App\Models\DeliveryAssignment;
use App\Models\SellerOrder;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DeliveryController extends Controller
{
    // ══════════════════════════════════════════════════════════════════════
    // DELIVERY ADMIN ENDPOINTS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * GET /api/delivery/orders
     * Returns seller_orders ready for delivery (processing OR completed),
     * not yet assigned to a delivery guy.
     */
    public function readyOrders(Request $request)
    {
        $orders = SellerOrder::whereIn('status', ['processing', 'completed'])
            ->whereDoesntHave('deliveryAssignment')
            ->with([
                'order.user:id,name,email',
                'items.product:id,name',
                'seller:id,name,email',
                // Load seller's approved application for pickup address & phone
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
     * Returns all orders currently assigned or in-transit.
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
                'ready_for_pickup' => SellerOrder::whereIn('status', ['processing', 'completed'])
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

        if (!in_array($sellerOrder->status, ['processing', 'completed'])) {
            return response()->json([
                'success' => false,
                'message' => 'Only orders with status "processing" or "completed" can be assigned.',
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
     * Delivery guy sees seller info so he knows where to PICK UP the order.
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
            $update = ['status' => $newStatus];
            if ($newStatus === 'picked_up') $update['picked_up_at'] = now();
            if ($newStatus === 'delivered')  $update['delivered_at'] = now();

            $assignment->update($update);

            $sellerOrderStatus = match ($newStatus) {
                'picked_up' => 'processing',
                'delivered' => 'delivered',
                'canceled'  => 'completed',
                default     => 'processing',
            };
            $assignment->sellerOrder->update(['status' => $sellerOrderStatus]);

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

            // ── Delivery destination (CLIENT) ──────────────────────────────
            'wilaya'         => $order?->wilaya,
            'address'        => $order?->address,
            'phone'          => $order?->phone,
            'notes'          => $order?->notes,
            'customer'       => $order?->user ? [
                'id'    => $order->user->id,
                'name'  => $order->user->name,
                'email' => $order->user->email,
            ] : null,

            // ── Pickup origin (SELLER) ─────────────────────────────────────
            // business_name : the shop/brand name from the seller application
            // name          : the seller's account name (User.name)
            // email         : the seller's login email (User.email)
            // phone         : the contact number from the seller application
            // wilaya + city : the seller's registered location (pickup address)
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
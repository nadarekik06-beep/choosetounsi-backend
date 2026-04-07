<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\SellerOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SellerOrderController
 *
 * ── KEY ARCHITECTURAL CHANGE ──
 * All operations now target the `seller_orders` table instead of `orders`.
 *
 * Previously: sellers queried `orders` using a list of order_ids derived from
 * their products — which meant they shared and could overwrite the same
 * `orders.status` field that other sellers' items also depended on.
 *
 * Now: each seller has their own `seller_orders` rows. Status and payment
 * updates are scoped exclusively to the authenticated seller's sub-orders.
 * Other sellers' sub-orders are completely unaffected.
 *
 * Frontend contract (unchanged shape):
 *   The response shape is identical to before so the existing seller orders
 *   page (orders/page.tsx) requires zero changes.
 */
class SellerOrderController extends Controller
{
    /**
     * Base query: only this seller's sub-orders.
     * All methods MUST call this — never query seller_orders without it.
     */
    private function sellerOrderQuery(int $sellerId)
    {
        return SellerOrder::where('seller_id', $sellerId)
            ->with([
                'order.user:id,name,email',
                'items.product:id,name,slug',
                'items.variant.attributeOptions.attribute',
            ]);
    }

    /* ── GET /api/seller/orders/stats ── */
    public function stats(Request $request)
    {
        $sellerId = auth()->id();
        $base     = SellerOrder::where('seller_id', $sellerId);

        return response()->json(['success' => true, 'data' => [
            'total'     => (clone $base)->count(),
            'pending'   => (clone $base)->where('status', 'pending')->count(),
            'completed' => (clone $base)->where('status', 'completed')->count(),
            'delivered' => (clone $base)->where('status', 'delivered')->count(),
            'cancelled' => (clone $base)->where('status', 'cancelled')->count(),
            'revenue'   => (clone $base)
                ->whereIn('status', ['completed', 'delivered'])
                ->sum('subtotal'),
        ]]);
    }

    /* ── GET /api/seller/orders ── */
    public function index(Request $request)
    {
        $sellerId = auth()->id();
        $query    = $this->sellerOrderQuery($sellerId);

        // ── Filters ────────────────────────────────────────────────────────
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
            $query->whereHas('order', function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhereHas('user', fn($u) =>
                      $u->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                  );
            });
        }

        $sellerOrders = $query->latest()->paginate((int) $request->query('per_page', 12));

        // ── Normalise response shape ──────────────────────────────────────
        // Transform to the same shape the frontend already expects, so the
        // existing seller orders page works without modification.
        $sellerOrders->getCollection()->transform(fn($so) => $this->formatSellerOrder($so));

        return response()->json(['success' => true, 'data' => $sellerOrders]);
    }

    /* ── GET /api/seller/orders/{id} ── */
    public function show(Request $request, $id)
    {
        $sellerId    = auth()->id();
        $sellerOrder = $this->sellerOrderQuery($sellerId)->findOrFail($id);

        // Map items to the normalised shape the detail modal expects
        $mappedItems = $sellerOrder->items->map(function ($item) {
            $productName = $item->product_name
                ?? $item->product?->name
                ?? "Product #{$item->product_id}";

            return [
                'id'           => $item->id,
                'product_id'   => $item->product_id,
                'product_name' => $productName,
                'quantity'     => (int) $item->quantity,
                'unit_price'   => (float) $item->unit_price,
                'total'        => (float) $item->total,
            ];
        });

        $order    = $sellerOrder->order;
        $customer = $order->user ? [
            'name'  => $order->user->name,
            'email' => $order->user->email,
        ] : null;

        return response()->json([
            'success' => true,
            'data'    => [
                'order' => array_merge($order->toArray(), [
                    'status'         => $sellerOrder->status,          // seller-scoped status
                    'payment_status' => $sellerOrder->payment_status,  // seller-scoped payment
                    'wilaya'         => $order->wilaya ?? $order->shipping_address ?? null,
                    'customer'       => $customer,
                    // seller_order_id so the frontend knows which sub-order this is
                    'seller_order_id' => $sellerOrder->id,
                ]),
                'items'           => $mappedItems->values(),
                'seller_subtotal' => round((float) $sellerOrder->subtotal, 3),
            ],
        ]);
    }

    /* ── PATCH /api/seller/orders/{id}/status ── */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:pending,processing,completed,delivered,cancelled',
        ]);

        $sellerId    = auth()->id();

        // SECURITY: findOrFail scoped to seller_id — a seller can NEVER update
        // another seller's sub-order even if they guess the ID.
        $sellerOrder = SellerOrder::where('seller_id', $sellerId)->findOrFail($id);
        $sellerOrder->update(['status' => $request->status]);

        return response()->json([
            'success' => true,
            'message' => 'Order status updated.',
            'data'    => $sellerOrder,
        ]);
    }

    /* ── PATCH /api/seller/orders/{id}/payment ── */
    public function updatePayment(Request $request, $id)
    {
        $request->validate([
            'payment_status' => 'required|in:unpaid,paid,refunded',
        ]);

        $sellerId    = auth()->id();
        $sellerOrder = SellerOrder::where('seller_id', $sellerId)->findOrFail($id);
        $sellerOrder->update(['payment_status' => $request->payment_status]);

        return response()->json([
            'success' => true,
            'message' => 'Payment status updated.',
            'data'    => $sellerOrder,
        ]);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Transform a SellerOrder into the flat shape the seller orders table
     * expects — mirrors the old `Order` shape so the frontend is unchanged.
     */
    private function formatSellerOrder(SellerOrder $so): array
    {
        $order = $so->order;
        return [
            // Use the seller_order.id as the primary ID so status updates
            // hit the right endpoint (/api/seller/orders/{seller_order.id}/status)
            'id'             => $so->id,
            'order_number'   => $order?->order_number,
            'status'         => $so->status,           // seller-scoped ✓
            'payment_status' => $so->payment_status,   // seller-scoped ✓
            'total_amount'   => (float) $so->subtotal,
            'wilaya'         => $order?->wilaya ?? $order?->shipping_address ?? null,
            'created_at'     => $so->created_at,
            'updated_at'     => $so->updated_at,
            'user_id'        => $order?->user_id,
            'user'           => $order?->user ? [
                'id'    => $order->user->id,
                'name'  => $order->user->name,
                'email' => $order->user->email,
            ] : null,
            // Parent order id — useful if admin needs cross-reference
            'parent_order_id' => $order?->id,
        ];
    }
}
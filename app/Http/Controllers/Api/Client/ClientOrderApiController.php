<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\Buyer\BuyerOrderResource;
use App\Models\Complaint;
use App\Models\Order;
use Illuminate\Http\Request;

/**
 * FILE: app/Http/Controllers/Api/Client/ClientOrderApiController.php  ← REPLACE
 *
 * Change from previous version:
 *   transformOrder() now adds is_returned (bool) and refund_status (string|null)
 *   to each order item so the frontend can display a "Returned" badge.
 *
 *   Logic:
 *     - Load approved complaints for this order that have resolution_type = 'return_refund'
 *       AND refund_status = 'completed' (delivery agent finished the pickup).
 *     - Collect the order_item_ids from those complaints into a flat set.
 *     - Each item whose id is in that set gets is_returned = true.
 *     - Legacy complaints (order_item_ids = null) mark ALL items as returned.
 *
 *   All existing fields and logic are 100% preserved — purely additive.
 */
class ClientOrderApiController extends Controller
{
    /**
     * GET /api/client/orders
     */
    public function index(Request $request)
    {
        $orders = Order::where('user_id', $request->user()->id)
            ->with([
                'sellerOrders',
                'sellerOrders.seller:id,name',
                'sellerOrders.deliveryAssignment.deliveryGuy:id,name',
                'items.variant.attributeOptions.attribute',
                'items.variant.images',
                'items' => fn($q) => $q->with([
                    'product' => fn($pq) => $pq->withTrashed()->with(['images', 'primaryImage']),
                ]),
                'complaints' => fn($q) => $q->select('id', 'order_id', 'reference', 'status', 'refund_amount', 'refund_method', 'refund_reference', 'refunded_at', 'created_at'),
            ])
            ->orderByDesc('created_at')
            ->paginate(20);

        // Explicit allow-list (App\Http\Resources\Buyer): never the raw models
        $orders->getCollection()->transform(fn($order) => (new BuyerOrderResource($order))->resolve($request));

        return response()->json(['success' => true, 'data' => $orders]);
    }

    /**
     * GET /api/client/orders/{id}
     */
    public function show(Request $request, $id)
    {
        $order = Order::where('user_id', $request->user()->id)
            ->with([
                'sellerOrders',
                'sellerOrders.seller:id,name',
                'sellerOrders.deliveryAssignment.deliveryGuy:id,name',
                'items.variant.attributeOptions.attribute',
                'items.variant.images',
                'items' => fn($q) => $q->with([
                    'product' => fn($pq) => $pq->withTrashed()->with(['images', 'primaryImage']),
                ]),
                'complaints' => fn($q) => $q->select('id', 'order_id', 'reference', 'status', 'refund_amount', 'refund_method', 'refund_reference', 'refunded_at', 'created_at'),
            ])
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => (new BuyerOrderResource($order))->resolve($request)]);
    }

    /**
     * GET /api/client/statistics
     */
    public function statistics(Request $request)
    {
        $userId = $request->user()->id;
        $base   = Order::where('user_id', $userId);

        return response()->json(['success' => true, 'data' => [
            'total'            => (clone $base)->count(),
            'pending'          => (clone $base)->where('status', 'pending')->count(),
            'completed'        => (clone $base)->where('status', 'completed')->count(),
            'delivered'        => (clone $base)->where('status', 'delivered')->count(),
            'out_for_delivery' => (clone $base)->where('status', 'out_for_delivery')->count(),
            'cancelled'        => (clone $base)->where('status', 'cancelled')->count(),
            'refunded'         => (clone $base)->where('status', 'refunded')->count(),
            'partially_returned' => (clone $base)->where('return_status', 'partial')->whereNotIn('status', ['cancelled', 'refunded'])->count(),
        ]]);
    }
}

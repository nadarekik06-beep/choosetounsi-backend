<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

/**
 * Seller Order API Controller
 * Shows orders that contain at least one product belonging to this seller.
 */
class SellerOrderController extends Controller
{
    /**
     * GET /api/seller/orders
     */
    public function index(Request $request)
    {
        $seller = auth()->user();

        $query = Order::with([
            'user:id,name,email',   // ← FIXED: removed 'state' which doesn't exist
            'items',
        ])
        // Only orders that contain this seller's products
        ->whereHas('items.product', function ($q) use ($seller) {
            $q->where('user_id', $seller->id);
        });

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        $orders = $query->latest()->paginate((int) $request->query('per_page', 12));

        return response()->json([
            'success' => true,
            'data'    => $orders,
        ]);
    }

    /**
     * GET /api/seller/orders/{id}
     */
    public function show(Request $request, $id)
    {
        $seller = auth()->user();

        $order = Order::with([
            'user:id,name,email',
            'items',
        ])
        ->whereHas('items.product', function ($q) use ($seller) {
            $q->where('user_id', $seller->id);
        })
        ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $order,
        ]);
    }

    /**
     * GET /api/seller/orders/stats
     */
    public function stats(Request $request)
    {
        $seller = auth()->user();

        // Base: orders containing this seller's products
        $base = Order::whereHas('items.product', function ($q) use ($seller) {
            $q->where('user_id', $seller->id);
        });

        return response()->json([
            'success' => true,
            'data'    => [
                'total'     => (clone $base)->count(),
                'pending'   => (clone $base)->where('status', 'pending')->count(),
                'completed' => (clone $base)->where('status', 'completed')->count(),
                'delivered' => (clone $base)->where('status', 'delivered')->count(),
                'cancelled' => (clone $base)->where('status', 'cancelled')->count(),
                'revenue'   => (clone $base)->whereIn('status', ['completed', 'delivered'])
                                            ->sum('total_amount'),
            ],
        ]);
    }

    /**
     * PATCH /api/seller/orders/{id}/status
     * Sellers can only mark as processing or cancelled.
     */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:processing,cancelled',
        ]);

        $seller = auth()->user();

        $order = Order::whereHas('items.product', function ($q) use ($seller) {
            $q->where('user_id', $seller->id);
        })->findOrFail($id);

        $order->update(['status' => $request->status]);

        return response()->json([
            'success' => true,
            'message' => 'Order status updated.',
            'data'    => $order,
        ]);
    }
}
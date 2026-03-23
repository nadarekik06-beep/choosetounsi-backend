<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

class ClientOrderApiController extends Controller
{
    /* ── GET /api/client/orders ── */
    public function index(Request $request)
    {
        $client = $request->user();

        $query = $client->orders()->with(['items.product.primaryImage']);

        if ($request->status) {
            $query->where('status', $request->status);
        }

        $orders = $query->latest()->paginate(15);

        return response()->json(['success' => true, 'data' => $orders]);
    }

    /* ── GET /api/client/orders/{order} ── */
    public function show(Request $request, Order $order)
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $order->load(['items.product.primaryImage']);

        return response()->json(['success' => true, 'data' => $order]);
    }

    /* ── GET /api/client/statistics ── */
    public function statistics(Request $request)
    {
        $client = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'total_orders'     => $client->orders()->count(),
                'pending_orders'   => $client->orders()->pending()->count(),
                'completed_orders' => $client->orders()->completed()->count(),
                'total_spent'      => round((float) $client->orders()
                    ->whereIn('status', ['completed', 'delivered'])
                    ->sum('total_amount'), 3),
            ],
        ]);
    }
}
<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

/**
 * Client Order API Controller
 * Returns JSON — used by the Next.js frontend at /api/client/orders
 */
class ClientOrderApiController extends Controller
{
    /**
     * GET /api/client/orders
     */
    public function index(Request $request)
    {
        $client = auth()->user();

        $query = Order::with(['items'])   // ← removed items.product to avoid crash
                      ->where('user_id', $client->id);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        $orders = $query->latest()->paginate((int) $request->query('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => $orders,
        ]);
    }

    /**
     * GET /api/client/orders/{orderId}
     */
    public function show(Request $request, $orderId)
    {
        $client = auth()->user();

        $order = Order::with(['items'])
                      ->where('user_id', $client->id)
                      ->findOrFail($orderId);

        return response()->json([
            'success' => true,
            'data'    => $order,
        ]);
    }

    /**
     * GET /api/client/statistics
     */
    public function statistics(Request $request)
    {
        $client = auth()->user();

        $base = Order::where('user_id', $client->id);

        return response()->json([
            'success' => true,
            'data'    => [
                'total'     => (clone $base)->count(),
                'pending'   => (clone $base)->where('status', 'pending')->count(),
                'completed' => (clone $base)->where('status', 'completed')->count(),
                'delivered' => (clone $base)->where('status', 'delivered')->count(),
                'cancelled' => (clone $base)->where('status', 'cancelled')->count(),
                'spent'     => (clone $base)->whereIn('status', ['completed', 'delivered'])
                                            ->sum('total_amount'),
            ],
        ]);
    }
}
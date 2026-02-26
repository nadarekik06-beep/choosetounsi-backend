<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

/**
 * Client Order API Controller
 * Clients view their own orders
 */
class ClientOrderApiController extends Controller
{
    /**
     * Get client's orders
     */
    public function index(Request $request)
    {
        $client = $request->user();
        
        $query = $client->orders();

        // Filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        $orders = $query->latest()->paginate(15);

        return response()->json($orders);
    }

    /**
     * Get client statistics
     */
    public function statistics(Request $request)
    {
        $client = $request->user();

        $statistics = [
            'total_orders' => $client->orders()->count(),
            'pending_orders' => $client->orders()->pending()->count(),
            'completed_orders' => $client->orders()->completed()->count(),
            'total_spent' => $client->orders()->completed()->sum('total_amount'),
        ];

        return response()->json($statistics);
    }

    /**
     * Get single order
     */
    public function show(Request $request, Order $order)
    {
        // Authorize
        if ($order->user_id !== $request->user()->id) {
            abort(403, 'You do not have permission to view this order.');
        }

        return response()->json($order);
    }
}
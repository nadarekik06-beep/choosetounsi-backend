<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

/**
 * Client Order Controller
 * Allows clients to view their own orders
 */
class ClientOrderController extends Controller
{
    /**
     * Display client's orders
     */
    public function index(Request $request)
    {
        $client = auth()->user();
        
        $query = $client->orders();

        // Filter by status
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        // Filter by date range
        if ($request->has('from_date') && $request->from_date) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        $orders = $query->latest()->paginate(15);

        return view('client.orders.index', compact('orders'));
    }

    /**
     * Show order details
     */
    public function show(Order $order)
    {
        // Ensure client owns this order
        $this->authorizeOrder($order);

        return view('client.orders.show', compact('order'));
    }

    /**
     * Ensure the authenticated client owns the order
     */
    protected function authorizeOrder(Order $order)
    {
        if ($order->user_id !== auth()->id()) {
            abort(403, 'You do not have permission to view this order.');
        }
    }
}
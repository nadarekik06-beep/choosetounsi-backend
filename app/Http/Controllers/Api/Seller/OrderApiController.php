<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

/**
 * Seller Order API Controller
 * Read-only orders for sellers
 */
class OrderApiController extends Controller
{
    /**
     * Get seller's orders
     */
    public function index(Request $request)
    {
        // Placeholder: In production, filter by orders containing seller's products
        $query = Order::query()->with('user');

        // Filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        $orders = $query->latest()->paginate(15);

        return response()->json($orders);
    }

    /**
     * Get order statistics
     */
    public function statistics(Request $request)
    {
        $statistics = [
            'total_orders' => Order::count(),
            'completed_orders' => Order::completed()->count(),
            'pending_orders' => Order::pending()->count(),
            'total_revenue' => Order::completed()->sum('total_amount'),
        ];

        return response()->json($statistics);
    }

    /**
     * Get single order
     */
    public function show(Request $request, Order $order)
    {
        $order->load('user');

        return response()->json($order);
    }
}
<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

class ClientOrderApiController extends Controller
{
    public function index(Request $request)
    {
        try {
            $orders = Order::where('user_id', $request->user()->id)
                ->with(['items'])
                ->orderByDesc('created_at')
                ->paginate(10);

            return response()->json([
                'success' => true,
                'data'    => $orders,
            ]);
        } catch (\Throwable $e) {
            \Log::error('ClientOrders error: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function show(Request $request, Order $order)
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }
        $order->load(['items']);
        return response()->json(['success' => true, 'data' => $order]);
    }

    public function statistics(Request $request)
    {
        $user = $request->user();
        return response()->json([
            'success' => true,
            'data' => [
                'total_orders'   => Order::where('user_id', $user->id)->count(),
                'pending_orders' => Order::where('user_id', $user->id)->where('status', 'pending')->count(),
                'total_spent'    => (float) Order::where('user_id', $user->id)->whereNotIn('status', ['cancelled'])->sum('total_amount'),
            ],
        ]);
    }
}
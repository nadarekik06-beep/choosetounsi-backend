<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /**
     * GET /api/admin/orders
     */
    public function index(Request $request)
    {
        $query = Order::with([
            'user:id,name,email',
            'items',              // ← load items only; no nested product here to avoid crash
        ]);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($u) use ($search) {
                      $u->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        if ($wilaya = $request->query('wilaya')) {
            $query->where('wilaya', $wilaya);
        }

        if ($dateFrom = $request->query('date_from')) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->query('date_to')) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $orders = $query->orderByDesc('created_at')
            ->paginate((int) $request->query('per_page', 15));

        return response()->json(['success' => true, 'data' => $orders]);
    }

    /**
     * GET /api/admin/orders/{id}
     */
    public function show($id)
    {
        $order = Order::with([
            'user:id,name,email',
            'items',              // load items; product data available via product_name snapshot
        ])->findOrFail($id);

        // Safely attempt to load product if the relation exists and product isn't deleted
        $order->items->each(function ($item) {
            try {
                $item->load('product');
            } catch (\Throwable $e) {
                // product relationship unavailable — product_name snapshot is still present
                $item->product = null;
            }
        });

        return response()->json(['success' => true, 'data' => $order]);
    }

    /**
     * PATCH /api/admin/orders/{id}/status
     */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:pending,processing,completed,delivered,cancelled,refunded',
        ]);

        $order = Order::findOrFail($id);
        $order->update(['status' => $request->status]);

        return response()->json([
            'success' => true,
            'message' => 'Order status updated.',
            'data'    => $order,
        ]);
    }

    /**
     * GET /api/admin/orders/stats
     */
    public function stats()
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'total'     => Order::count(),
                'pending'   => Order::pending()->count(),
                'completed' => Order::completed()->count(),
                'delivered' => Order::delivered()->count(),
                'cancelled' => Order::where('status', 'cancelled')->count(),
                'revenue'   => Order::completed()->sum('total_amount'),
            ],
        ]);
    }
}
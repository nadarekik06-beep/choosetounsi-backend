<?php
// app/Http/Controllers/Admin/OrderController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /**
     * GET /api/admin/orders
     * Supports: ?status=pending|processing|delivered|canceled  &search=  &date_from=  &date_to=
     */
    public function index(Request $request)
    {
        $query = Order::with(['user:id,name,email']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->query('search')) {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            })->orWhere('id', $search);
        }

        if ($dateFrom = $request->query('date_from')) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->query('date_to')) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $orders = $query->orderByDesc('created_at')
            ->paginate($request->query('per_page', 15));

        return response()->json(['success' => true, 'data' => $orders]);
    }

    /**
     * GET /api/admin/orders/{id}
     */
    public function show($id)
    {
        $order = Order::with([
            'user:id,name,email',
            'items.product:id,name,price',
        ])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $order]);
    }
}
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $query = Order::with(['user:id,name,email']);

        if ($s = $request->query('status'))    $query->where('status', $s);
        if ($s = $request->query('search'))    $query->whereHas('user', fn($q) => $q->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%"));
        if ($d = $request->query('date_from')) $query->whereDate('created_at', '>=', $d);
        if ($d = $request->query('date_to'))   $query->whereDate('created_at', '<=', $d);

        $orders = $query->orderByDesc('created_at')
            ->paginate((int) $request->query('per_page', 15));

        return response()->json(['success' => true, 'data' => $orders]);
    }

    public function show($id)
    {
        $order = Order::with([
            'user:id,name,email',
            'items',
            'items.product:id,name,slug',
            'items.product.primaryImage',
            // Load variant with images so we can show the right image per item
            'items.variant:id,product_id,sku',
            'items.variant.images',
            'items.variant.attributeOptions.attribute:id,slug,name,type',
        ])->findOrFail($id);

        // Append resolved image URL to each item
        $order->items->each(function ($item) {
            $item->resolved_image_url = $this->resolveItemImage($item);

            // Attach variant option map for display
            if ($item->variant && $item->variant->relationLoaded('attributeOptions')) {
                $item->variant_options = $item->variant->attributeOptions
                    ->mapWithKeys(fn($o) => [
                        $o->attribute->slug => [
                            'value'     => $o->value,
                            'color_hex' => $o->color_hex,
                        ],
                    ]);
            } else {
                $item->variant_options = [];
            }
        });

        return response()->json(['success' => true, 'data' => $order]);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|string|in:pending,processing,completed,cancelled,delivered,refunded',
        ]);

        try {
            // DB::table bypasses Eloquent model events and decimal casting
            // which is the root cause of the 500 on Order::update()
            DB::table('seller_orders')
                ->where('order_id', $id)
                ->update([
                    'status'     => $request->status,
                    'updated_at' => now(),
        ]);

            $order = Order::findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'Status updated.',
                'data'    => $order,
            ]);

        } catch (\Throwable $e) {
            Log::error('[AdminOrder::updateStatus] ' . $e->getMessage(), [
                'order_id' => $id,
                'status'   => $request->status,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update status: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ── Private ────────────────────────────────────────────────────────────

    private function resolveItemImage($item): ?string
    {
        // 1. Stored snapshot (set at checkout)
        if (!empty($item->image_url)) {
            return str_starts_with($item->image_url, 'http') ? $item->image_url : url($item->image_url);
        }
        // 2. Variant-linked image
        if ($item->variant && $item->variant->images->isNotEmpty()) {
            return Storage::url($item->variant->images->first()->image_path);
        }
        // 3. Product primary image
        if ($item->product && $item->product->primaryImage) {
            return Storage::url($item->product->primaryImage->image_path);
        }
        return null;
    }
    // Add to AdminOrderController — confirmPayment handles COD & D17

public function confirmPayment(Request $request, $id)
{
    $request->validate([
        'd17_reference' => 'nullable|string|max:100',
    ]);

    try {
        $order = Order::findOrFail($id);

        if (!in_array($order->payment_method, ['cod', 'd17'])) {
            return response()->json([
                'success' => false,
                'message' => 'Only COD and D17 orders require manual payment confirmation.',
            ], 422);
        }

        $updateData = [
            'payment_status' => 'paid',
            'status'         => 'processing',
            'updated_at'     => now(),
        ];

        if ($request->d17_reference) {
            $updateData['d17_reference'] = $request->d17_reference;
        }

        DB::table('orders')->where('id', $id)->update($updateData);

        // Cascade payment confirmation to all seller_orders
        DB::table('seller_orders')
            ->where('order_id', $id)
            ->update(['payment_status' => 'paid', 'updated_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Payment confirmed.',
            'data'    => Order::findOrFail($id),
        ]);

    } catch (\Throwable $e) {
        Log::error('[AdminOrder::confirmPayment] ' . $e->getMessage(), ['order_id' => $id]);
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

// Add to AdminOrderController — stats endpoint (if not already present)
public function stats(Request $request)
{
    return response()->json(['success' => true, 'data' => [
        'total'     => Order::count(),
        'pending'   => Order::where('status', 'pending')->count(),
        'completed' => Order::where('status', 'completed')->count(),
        'delivered' => Order::where('status', 'delivered')->count(),
        'cancelled' => Order::where('status', 'cancelled')->count(),
        'revenue'   => Order::where('payment_status', 'paid')->sum('total_amount'),
    ]]);
}
}
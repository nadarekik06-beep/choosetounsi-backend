<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ClientOrderApiController extends Controller
{
    public function index(Request $request)
    {
        try {
            $orders = Order::where('user_id', $request->user()->id)
                ->with([
                    'items',
                    'items.product:id,name,slug',
                    'items.product.primaryImage',
                    'items.variant:id,product_id',
                    'items.variant.images',
                ])
                ->orderByDesc('created_at')
                ->paginate(10);

            $orders->getCollection()->transform(
                fn($o) => $this->appendItemImages($o)
            );

            return response()->json(['success' => true, 'data' => $orders]);
        } catch (\Throwable $e) {
            \Log::error('ClientOrders error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function show(Request $request, Order $order)
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        $order->load([
            'items',
            'items.product:id,name,slug',
            'items.product.primaryImage',
            'items.variant:id,product_id',
            'items.variant.images',
        ]);

        return response()->json(['success' => true, 'data' => $this->appendItemImages($order)]);
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

    // ── Private ────────────────────────────────────────────────────────────

    /**
     * Append resolved_image_url to each order item.
     * Priority: stored snapshot → variant image → product primary image
     */
    private function appendItemImages(Order $order): Order
    {
        $order->items->each(function ($item) {
            // 1. Stored snapshot
            if (!empty($item->image_url)) {
                $item->resolved_image_url = str_starts_with($item->image_url, 'http')
                    ? $item->image_url
                    : url($item->image_url);
                return;
            }

            // 2. Variant image
            if ($item->variant && $item->variant->images->isNotEmpty()) {
                $item->resolved_image_url = Storage::url(
                    $item->variant->images->first()->image_path
                );
                return;
            }

            // 3. Product primary image
            if ($item->product && $item->product->primaryImage) {
                $item->resolved_image_url = Storage::url(
                    $item->product->primaryImage->image_path
                );
                return;
            }

            $item->resolved_image_url = null;
        });

        return $order;
    }
}
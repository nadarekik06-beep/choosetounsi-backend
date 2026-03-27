<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

class ClientOrderApiController extends Controller
{
    /**
     * GET /api/client/orders
     * List the authenticated user's orders (paginated).
     */
    public function index(Request $request)
    {
        $orders = Order::where('user_id', $request->user()->id)
            ->with([
                'items.product.images',
            ])
            ->orderByDesc('created_at')
            ->paginate(10);

        // Attach image URLs and format items
        $orders->getCollection()->transform(fn($order) => $this->formatOrder($order));

        return response()->json([
            'success' => true,
            'data'    => $orders,
        ]);
    }

    /**
     * GET /api/client/orders/{order}
     * Show a single order's full details.
     */
    public function show(Request $request, Order $order)
    {
        // Ensure the order belongs to this user
        if ($order->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        $order->load(['items.product.images']);

        return response()->json([
            'success' => true,
            'data'    => $this->formatOrder($order),
        ]);
    }

    /**
     * GET /api/client/statistics
     */
    public function statistics(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data'    => [
                'total_orders'   => Order::where('user_id', $user->id)->count(),
                'pending_orders' => Order::where('user_id', $user->id)->where('status', 'pending')->count(),
                'total_spent'    => (float) Order::where('user_id', $user->id)
                    ->whereNotIn('status', ['cancelled'])
                    ->sum('total_amount'),
            ],
        ]);
    }

    // ── Private helpers ────────────────────────────────────────────────────

    private function formatOrder(Order $order): array
    {
        $data = $order->toArray();

        // Format each order item to include variant info
        $data['items'] = $order->items->map(function ($item) {
            $product = $item->product;

            // Resolve primary image URL from already-loaded images relation
            $imageUrl = null;
            if ($product) {
                $primary = $product->images
                    ->firstWhere('is_primary', true)
                    ?? $product->images->first();
                if ($primary) {
                    $imageUrl = rtrim(config('app.url'), '/') . '/storage/' . ltrim($primary->image_path, '/');
                }
            }

            return [
                'id'            => $item->id,
                'product_id'    => $item->product_id,
                'variant_id'    => $item->variant_id,
                'variant_label' => $item->variant_label,
                'product_name'  => $item->product_name,
                'quantity'      => $item->quantity,
                'unit_price'    => (float) $item->unit_price,
                'total'         => (float) $item->total,
                'product'       => $product ? [
                    'id'                => $product->id,
                    'slug'              => $product->slug,
                    'primary_image_url' => $imageUrl,
                ] : null,
            ];
        })->values()->toArray();

        return $data;
    }
}
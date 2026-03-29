<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ClientOrderApiController extends Controller
{
    /**
     * GET /api/client/orders
     * Returns the authenticated user's orders with variant-aware images.
     */
    public function index(Request $request)
    {
        $orders = Order::where('user_id', $request->user()->id)
            ->with([
                'items.product.images',
                'items.product.primaryImage',
                'items.variant.attributeOptions.attribute',
                'items.variant.images',
            ])
            ->orderByDesc('created_at')
            ->paginate(20);

        $orders->getCollection()->transform(function ($order) {
            $order->items->transform(function ($item) {
                $item->resolved_image_url = $this->resolveImageUrl(
                    $item->product,
                    $item->variant
                );
                return $item;
            });
            return $order;
        });

        return response()->json(['success' => true, 'data' => $orders]);
    }

    /**
     * GET /api/client/orders/{id}
     */
    public function show(Request $request, $id)
    {
        $order = Order::where('user_id', $request->user()->id)
            ->with([
                'items.product.images',
                'items.product.primaryImage',
                'items.variant.attributeOptions.attribute',
                'items.variant.images',
            ])
            ->findOrFail($id);

        $order->items->transform(function ($item) {
            $item->resolved_image_url = $this->resolveImageUrl(
                $item->product,
                $item->variant
            );
            return $item;
        });

        return response()->json(['success' => true, 'data' => $order]);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Image priority:
     *  1. Variant's own images
     *  2. Product images with variant's color_option_id
     *  3. Product primary image (fallback)
     *  4. null
     */
    private function resolveImageUrl(?Product $product, ?ProductVariant $variant): ?string
    {
        if (!$product) return null;

        if ($variant) {
            // 1. Variant's own images
            if ($variant->relationLoaded('images') && $variant->images->isNotEmpty()) {
                $img = $variant->images->firstWhere('is_primary', true)
                    ?? $variant->images->sortBy('order')->first();
                if ($img) return Storage::url($img->image_path);
            }

            // 2. Color-grouped product images
            $colorOptId = null;
            if ($variant->relationLoaded('attributeOptions')) {
                $colorOpt   = $variant->attributeOptions->first(
                    fn($o) => $o->attribute->slug === 'color'
                );
                $colorOptId = $colorOpt?->id;
            }

            if ($colorOptId && $product->relationLoaded('images')) {
                $img = $product->images
                    ->where('color_option_id', $colorOptId)
                    ->sortBy('order')
                    ->first();
                if ($img) return Storage::url($img->image_path);
            }
        }

        // 3. Product primary image fallback
        if ($product->relationLoaded('images')) {
            $img = $product->images->firstWhere('is_primary', true)
                ?? $product->images->sortBy('order')->first();
            if ($img) return Storage::url($img->image_path);
        }

        return null;
    }
}
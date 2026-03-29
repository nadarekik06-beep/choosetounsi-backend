<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;

class CartController extends Controller
{
    /* ── GET /api/cart ── */
    public function index(Request $request)
    {
        $items = Cart::with([
            'product.primaryImage',
            'product.category',
            'variant.attributeOptions.attribute',
            'variant.images',
        ])
            ->where('user_id', $request->user()->id)
            ->get()
            ->map(fn($item) => $this->formatItem($item));

        $subtotal = $items->sum('line_total');

        return response()->json([
            'success' => true,
            'data' => [
                'items'    => $items,
                'count'    => $items->sum('quantity'),
                'subtotal' => round($subtotal, 3),
            ],
        ]);
    }

    /* ── POST /api/cart ── */
    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'variant_id' => 'nullable|exists:product_variants,id',
            'quantity'   => 'sometimes|integer|min:1|max:100',
        ]);

        $product = Product::findOrFail($request->product_id);

        if (!$product->is_approved || !$product->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Product is not available.',
            ], 422);
        }

        $qty     = $request->input('quantity', 1);
        $variant = null;

        if ($request->filled('variant_id')) {
            $variant = ProductVariant::where('id', $request->variant_id)
                ->where('product_id', $product->id)
                ->where('is_active', true)
                ->first();

            if (!$variant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected variant is unavailable.',
                ], 422);
            }

            $stockPool = $variant->stock;
        } elseif ($product->has_variants) {
            return response()->json([
                'success' => false,
                'message' => 'Please select a variant before adding to cart.',
            ], 422);
        } else {
            $stockPool = $product->stock;
        }

        if ($stockPool < $qty) {
            return response()->json([
                'success' => false,
                'message' => "Only {$stockPool} items in stock.",
            ], 422);
        }

        $cartItem = Cart::firstOrNew([
            'user_id'    => $request->user()->id,
            'product_id' => $product->id,
            'variant_id' => $variant?->id,
        ]);

        $newQty = ($cartItem->exists ? $cartItem->quantity : 0) + $qty;

        if ($newQty > $stockPool) {
            return response()->json([
                'success' => false,
                'message' => "Cannot add more — only {$stockPool} in stock.",
            ], 422);
        }

        $cartItem->quantity = $newQty;
        $cartItem->save();

        return response()->json([
            'success' => true,
            'message' => 'Added to cart.',
            'data'    => $this->formatItem(
                $cartItem->fresh([
                    'product.primaryImage',
                    'product.category',
                    'variant.attributeOptions.attribute',
                    'variant.images',
                ])
            ),
        ], 201);
    }

    /* ── PUT /api/cart/{id} ── */
    public function update(Request $request, $id)
    {
        $request->validate(['quantity' => 'required|integer|min:1|max:100']);

        $cartItem = Cart::with(['product', 'variant'])
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $stockPool = $cartItem->variant
            ? $cartItem->variant->stock
            : $cartItem->product->stock;

        if ($request->quantity > $stockPool) {
            return response()->json([
                'success' => false,
                'message' => "Only {$stockPool} in stock.",
            ], 422);
        }

        $cartItem->update(['quantity' => $request->quantity]);

        return response()->json([
            'success' => true,
            'message' => 'Cart updated.',
            'data'    => $this->formatItem(
                $cartItem->fresh([
                    'product.primaryImage',
                    'product.category',
                    'variant.attributeOptions.attribute',
                    'variant.images',
                ])
            ),
        ]);
    }

    /* ── DELETE /api/cart/{id} ── */
    public function destroy(Request $request, $id)
    {
        Cart::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['success' => true, 'message' => 'Item removed from cart.']);
    }

    /* ── DELETE /api/cart ── */
    public function clear(Request $request)
    {
        Cart::where('user_id', $request->user()->id)->delete();
        return response()->json(['success' => true, 'message' => 'Cart cleared.']);
    }

    /* ── Private helper ── */
    private function formatItem(Cart $item): array
    {
        $product  = $item->product;
        $variant  = $item->variant;

        // IMAGE PRIORITY: variant image → product primary image → null
        if ($variant && $variant->relationLoaded('images') && $variant->images->isNotEmpty()) {
            $imageUrl = rtrim(config('app.url'), '/') . '/storage/' . ltrim($variant->images->first()->image_path, '/');
        } elseif ($product->primaryImage) {
            $imageUrl = rtrim(config('app.url'), '/') . '/storage/' . ltrim($product->primaryImage->image_path, '/');
        } else {
            $imageUrl = null;
        }

        $effectivePrice = $variant
            ? (float) ($variant->price_override ?? $product->price)
            : (float) $product->price;

        $stock = $variant ? $variant->stock : $product->stock;

        $variantOptions = [];
        if ($variant && $variant->relationLoaded('attributeOptions')) {
            foreach ($variant->attributeOptions as $opt) {
                $variantOptions[$opt->attribute->slug] = [
                    'id'        => $opt->id,
                    'value'     => $opt->value,
                    'color_hex' => $opt->color_hex,
                ];
            }
        }

        $variantLabel = $variant
            ? $variant->attributeOptions->pluck('value')->join(' / ')
            : null;

        return [
            'id'              => $item->id,
            'product_id'      => $product->id,
            'variant_id'      => $variant?->id,
            'variant_label'   => $variantLabel,
            'variant_options' => $variantOptions,
            'name'            => $product->name,
            'slug'            => $product->slug,
            'sku'             => $variant?->sku ?? $product->sku,
            'price'           => $effectivePrice,
            'quantity'        => $item->quantity,
            'line_total'      => round($effectivePrice * $item->quantity, 3),
            'stock'           => $stock,
            'image_url'       => $imageUrl,
            'category'        => $product->category?->name,
        ];
    }
}
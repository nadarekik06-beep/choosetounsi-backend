<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CartController extends Controller
{
    /**
     * GET /api/cart
     * Returns all cart items with variant-aware image URLs.
     */
    public function index(Request $request)
    {
<<<<<<< HEAD
        $items = Cart::with([
            'product.primaryImage',
            'product.category',
            'variant.attributeOptions.attribute',
            'variant.images',
        ])
            ->where('user_id', $request->user()->id)
            ->get()
            ->map(fn($item) => $this->formatItem($item));
=======
        $user  = $request->user();
        $items = Cart::where('user_id', $user->id)
            ->with([
                'product.images',
                'product.primaryImage',
                'product.category:id,name',
                'variant.attributeOptions.attribute',
                'variant.images',
            ])
            ->get();
>>>>>>> b06fc03 (Abdou's changes)

        $mapped = $items->map(fn($item) => $this->formatCartItem($item));

        return response()->json([
            'success' => true,
            'data'    => [
                'items'    => $mapped,
                'count'    => $mapped->sum('quantity'),
                'subtotal' => round($mapped->sum('line_total'), 3),
            ],
        ]);
    }

    /**
     * POST /api/cart
     * Add product (optionally with variant) to cart.
     */
    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'variant_id' => 'nullable|exists:product_variants,id',
            'quantity'   => 'integer|min:1|max:100',
        ]);

        $user      = $request->user();
        $productId = $request->product_id;
        $variantId = $request->variant_id ?? null;
        $qty       = $request->quantity   ?? 1;

<<<<<<< HEAD
        if (!$product->is_approved || !$product->is_active) {
=======
        $product = Product::findOrFail($productId);

        // If product has variants, variant_id is required
        if ($product->variants()->exists() && !$variantId) {
>>>>>>> b06fc03 (Abdou's changes)
            return response()->json([
                'success' => false,
                'message' => 'Please select a variant.',
            ], 422);
        }

<<<<<<< HEAD
        $qty     = $request->input('quantity', 1);
        $variant = null;

        if ($request->filled('variant_id')) {
            $variant = ProductVariant::where('id', $request->variant_id)
                ->where('product_id', $product->id)
                ->where('is_active', true)
                ->first();

            if (!$variant) {
=======
        // Check stock
        if ($variantId) {
            $variant = ProductVariant::findOrFail($variantId);
            if ($variant->stock < $qty) {
>>>>>>> b06fc03 (Abdou's changes)
                return response()->json([
                    'success' => false,
                    'message' => 'Not enough stock for this variant.',
                ], 422);
            }
<<<<<<< HEAD

            $stockPool = $variant->stock;
        } elseif ($product->has_variants) {
            return response()->json([
                'success' => false,
                'message' => 'Please select a variant before adding to cart.',
            ], 422);
=======
>>>>>>> b06fc03 (Abdou's changes)
        } else {
            if ($product->stock < $qty) {
                return response()->json([
                    'success' => false,
                    'message' => 'Not enough stock.',
                ], 422);
            }
        }

        // Upsert: if same product+variant already in cart, increment
        $cartItem = Cart::where('user_id', $user->id)
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->first();

        if ($cartItem) {
            $cartItem->increment('quantity', $qty);
        } else {
            Cart::create([
                'user_id'    => $user->id,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'quantity'   => $qty,
            ]);
        }

<<<<<<< HEAD
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
=======
        return $this->index($request);
>>>>>>> b06fc03 (Abdou's changes)
    }

    /**
     * PUT /api/cart/{id}
     * Update quantity of a cart item.
     */
    public function update(Request $request, $id)
    {
        $request->validate(['quantity' => 'required|integer|min:1|max:100']);

        $item = Cart::where('user_id', $request->user()->id)->findOrFail($id);
        $item->update(['quantity' => $request->quantity]);

<<<<<<< HEAD
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
=======
        return $this->index($request);
>>>>>>> b06fc03 (Abdou's changes)
    }

    /**
     * DELETE /api/cart/{id}
     */
    public function destroy(Request $request, $id)
    {
        Cart::where('user_id', $request->user()->id)->findOrFail($id)->delete();
        return $this->index($request);
    }

    /**
     * DELETE /api/cart
     * Clear entire cart.
     */
    public function clear(Request $request)
    {
        Cart::where('user_id', $request->user()->id)->delete();
        return response()->json([
            'success' => true,
            'data'    => ['items' => [], 'count' => 0, 'subtotal' => 0],
        ]);
    }

<<<<<<< HEAD
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
=======
    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Build a single cart item payload with the correct variant-aware image URL.
     *
     * Image priority:
     *   1. Variant's own images (most specific)
     *   2. Product images grouped by this variant's color option
     *   3. Product primary image (fallback for non-variant products)
     */
    private function formatCartItem(Cart $item): array
    {
        $product = $item->product;
        $variant = $item->variant;

        // ── Resolve price ──────────────────────────────────────────────────
        $price = $variant
            ? ($variant->price_override !== null
                ? (float) $variant->price_override
                : (float) $product->price)
>>>>>>> b06fc03 (Abdou's changes)
            : (float) $product->price;

        // ── Resolve image URL ──────────────────────────────────────────────
        $imageUrl = $this->resolveImageUrl($product, $variant);

        // ── Resolve stock ──────────────────────────────────────────────────
        $stock = $variant ? $variant->stock : $product->stock;

<<<<<<< HEAD
=======
        // ── Variant label & options ────────────────────────────────────────
        $variantLabel   = null;
>>>>>>> b06fc03 (Abdou's changes)
        $variantOptions = [];

        if ($variant && $variant->relationLoaded('attributeOptions')) {
            $variantLabel = $variant->attributeOptions->pluck('value')->join(' / ');
            foreach ($variant->attributeOptions as $opt) {
                $variantOptions[$opt->attribute->slug] = [
                    'id'        => $opt->id,
                    'value'     => $opt->value,
                    'color_hex' => $opt->color_hex,
                ];
            }
        }

        return [
            'id'              => $item->id,
            'product_id'      => $product->id,
            'variant_id'      => $variant?->id,
            'name'            => $product->name,
            'slug'            => $product->slug,
            'sku'             => $variant?->sku ?? $product->sku,
            'category'        => $product->category?->name,
            'price'           => $price,
            'quantity'        => $item->quantity,
            'stock'           => $stock,
            'line_total'      => round($price * $item->quantity, 3),
            'image_url'       => $imageUrl,
            'variant_label'   => $variantLabel,
            'variant_options' => $variantOptions,
        ];
    }

    /**
     * Resolve the best image URL for a cart item.
     *
     * Priority:
     *  1. Variant's own images (variant_id match)
     *  2. Product images with this variant's color_option_id
     *  3. Product primary image
     *  4. First product image
     *  5. null
     */
    private function resolveImageUrl(Product $product, ?ProductVariant $variant): ?string
    {
        if ($variant) {
            // 1. Variant's own uploaded images
            if ($variant->relationLoaded('images') && $variant->images->isNotEmpty()) {
                $primary = $variant->images->firstWhere('is_primary', true)
                        ?? $variant->images->sortBy('order')->first();
                if ($primary) {
                    return Storage::url($primary->image_path);
                }
            }

            // 2. Product images grouped by this variant's color option
            $colorOptId = null;
            if ($variant->relationLoaded('attributeOptions')) {
                $colorOpt   = $variant->attributeOptions->first(
                    fn($o) => $o->attribute->slug === 'color'
                );
                $colorOptId = $colorOpt?->id;
            }

            if ($colorOptId && $product->relationLoaded('images')) {
                $colorImage = $product->images
                    ->where('color_option_id', $colorOptId)
                    ->sortBy('order')
                    ->first();
                if ($colorImage) {
                    return Storage::url($colorImage->image_path);
                }
            }
        }

        // 3. Product primary image (for non-variant products)
        if ($product->relationLoaded('images')) {
            $primary = $product->images->firstWhere('is_primary', true)
                    ?? $product->images->sortBy('order')->first();
            if ($primary) {
                return Storage::url($primary->image_path);
            }
        }

        return null;
    }
}
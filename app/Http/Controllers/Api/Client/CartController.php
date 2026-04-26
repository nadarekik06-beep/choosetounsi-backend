<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\UserPreferenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Api\UserPreferenceController;
use App\Http\Controllers\Api\ProductRecommendationController;

class CartController extends Controller
{
    private UserPreferenceService $preferenceService;

    public function __construct(UserPreferenceService $preferenceService)
    {
        $this->preferenceService = $preferenceService;
    }

    /**
     * GET /api/cart
     */
    public function index(Request $request)
    {
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
     *
     * FIX: Stock check now compares (existing_cart_qty + incoming_qty) against
     * actual stock — not just incoming_qty in isolation.
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

        $product = Product::findOrFail($productId);

        $this->ensureNotProductOwner($request, $product);

        if ($product->variants()->exists() && !$variantId) {
            return response()->json([
                'success' => false,
                'message' => 'Please select a variant.',
            ], 422);
        }

        // ── Find existing cart row ──────────────────────────────────────────
        $query = Cart::where('user_id', $user->id)
                     ->where('product_id', $productId);
        $query = $variantId
            ? $query->where('variant_id', $variantId)
            : $query->whereNull('variant_id');

        $cartItem = $query->first();

        // How many does the user already have in cart for this exact variant?
        $existingQty = $cartItem ? $cartItem->quantity : 0;

        // ── THE CORE FIX: check cumulative quantity against real stock ──────
        if ($variantId) {
            $variant = ProductVariant::findOrFail($variantId);

            if ($variant->product_id !== $product->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid variant for this product.',
                ], 422);
            }

            $available = $variant->stock;
            $requested = $existingQty + $qty;   // ← cumulative, not just incoming

            if ($requested > $available) {
                $canAdd = $available - $existingQty;
                return response()->json([
                    'success'   => false,
                    'message'   => $canAdd > 0
                        ? "Only {$canAdd} more item(s) can be added (stock: {$available}, in cart: {$existingQty})."
                        : "You already have all available stock ({$available}) in your cart.",
                    'available' => $available,
                    'in_cart'   => $existingQty,
                    'can_add'   => max(0, $canAdd),
                ], 422);
            }
        } else {
            $available = $product->stock;
            $requested = $existingQty + $qty;

            if ($requested > $available) {
                $canAdd = $available - $existingQty;
                return response()->json([
                    'success'   => false,
                    'message'   => $canAdd > 0
                        ? "Only {$canAdd} more item(s) can be added (stock: {$available}, in cart: {$existingQty})."
                        : "You already have all available stock ({$available}) in your cart.",
                    'available' => $available,
                    'in_cart'   => $existingQty,
                    'can_add'   => max(0, $canAdd),
                ], 422);
            }
        }

        // ── Upsert ────────────────────────────────────────────────────────────
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

        // ── Log cart activity ─────────────────────────────────────────────────
        $this->preferenceService->logActivity(
            userId:     $user->id,
            productId:  $productId,
            categoryId: $product->category_id,
            action:     'cart',
            sessionId:  $request->session()->getId()
        );

        return $this->index($request);
    }

    /**
     * PUT /api/cart/{id}
     *
     * FIX: Now validates requested quantity against real stock.
     * Clamps to available stock instead of blindly setting whatever
     * the client sends.
     */
    public function update(Request $request, $id)
    {
        $request->validate(['quantity' => 'required|integer|min:1|max:100']);

        $item = Cart::where('user_id', $request->user()->id)
            ->with(['variant', 'product'])
            ->findOrFail($id);

        // ── Resolve available stock for this specific item ──────────────────
        $available = $item->variant
            ? $item->variant->stock
            : $item->product->stock;

        $requested = (int) $request->quantity;

        // ── Clamp to available stock ────────────────────────────────────────
        if ($requested > $available) {
            if ($available <= 0) {
                // Item went out of stock since being added — remove it
                $item->delete();
                return $this->index($request);
            }
            // Silently clamp to max available instead of erroring
            // This handles the race condition where stock sold out
            // between the user loading the page and updating the cart
            $requested = $available;
        }

        $item->update(['quantity' => $requested]);

        return $this->index($request);
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
     */
    public function clear(Request $request)
    {
        Cart::where('user_id', $request->user()->id)->delete();
        return response()->json([
            'success' => true,
            'data'    => ['items' => [], 'count' => 0, 'subtotal' => 0],
        ]);
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function formatCartItem(Cart $item): array
    {
        $product = $item->product;
        $variant = $item->variant;

        $price = $variant
            ? ($variant->price_override !== null
                ? (float) $variant->price_override
                : (float) $product->price)
            : (float) $product->price;

        $imageUrl = $this->resolveImageUrl($product, $variant);
        $stock    = $variant ? $variant->stock : $product->stock;

        $variantLabel   = null;
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

    private function resolveImageUrl(Product $product, ?ProductVariant $variant): ?string
    {
        if ($variant) {
            if ($variant->relationLoaded('images') && $variant->images->isNotEmpty()) {
                $primary = $variant->images->firstWhere('is_primary', true)
                        ?? $variant->images->sortBy('order')->first();
                if ($primary) return Storage::url($primary->image_path);
            }

            $colorOptId = null;
            if ($variant->relationLoaded('attributeOptions')) {
                $colorOpt   = $variant->attributeOptions->first(fn($o) => $o->attribute->slug === 'color');
                $colorOptId = $colorOpt?->id;
            }

            if ($colorOptId && $product->relationLoaded('images')) {
                $colorImage = $product->images
                    ->where('color_option_id', $colorOptId)
                    ->sortBy('order')
                    ->first();
                if ($colorImage) return Storage::url($colorImage->image_path);
            }
        }

        if ($product->relationLoaded('images')) {
            $primary = $product->images->firstWhere('is_primary', true)
                    ?? $product->images->sortBy('order')->first();
            if ($primary) return Storage::url($primary->image_path);
        }

        return null;
    }
}
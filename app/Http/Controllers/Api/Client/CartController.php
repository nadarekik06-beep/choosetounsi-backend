<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Pack;
use App\Models\PackItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\UserPreferenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
                // Product row relations (only loaded when product_id is set)
                'product.images',
                'product.primaryImage',
                'product.category:id,name',
                'variant.attributeOptions.attribute',
                'variant.images',
                // Pack row relation (only loaded when pack_id is set)
                'pack',
            ])
            ->get();

        $mapped = $items->map(fn($item) => $item->isPack()
            ? $this->formatPackCartItem($item)
            : $this->formatCartItem($item)
        );

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
     * Accepts EITHER:
     *   A) Regular product:  { product_id, variant_id?, quantity? }
     *   B) Pack bundle:      { pack_id, pack_selections: [{pack_item_id, variant_id}] }
     *
     * Existing product logic is completely unchanged.
     * Pack logic is a new isolated branch that returns early.
     */
    public function store(Request $request)
    {
        // ── BRANCH B: Pack ────────────────────────────────────────────────────
        if ($request->filled('pack_id')) {
            return $this->storePack($request);
        }

        // ── BRANCH A: Regular product (original logic, untouched) ─────────────
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

        $query = Cart::where('user_id', $user->id)
                     ->where('product_id', $productId)
                     ->whereNull('pack_id');   // ← don't match pack rows
        $query = $variantId
            ? $query->where('variant_id', $variantId)
            : $query->whereNull('variant_id');

        $cartItem    = $query->first();
        $existingQty = $cartItem ? $cartItem->quantity : 0;

        if ($variantId) {
            $variant = ProductVariant::findOrFail($variantId);

            if ($variant->product_id !== $product->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid variant for this product.',
                ], 422);
            }

            $available = $variant->stock;
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
     */
    public function update(Request $request, $id)
    {
        $request->validate(['quantity' => 'required|integer|min:1|max:100']);

        $item = Cart::where('user_id', $request->user()->id)
            ->with(['variant', 'product'])
            ->findOrFail($id);

        // Pack rows: just update quantity directly (no stock check per-item)
        if ($item->isPack()) {
            $item->update(['quantity' => (int) $request->quantity]);
            return $this->index($request);
        }

        // Product rows: existing stock clamp logic
        $available = $item->variant
            ? $item->variant->stock
            : $item->product->stock;

        $requested = (int) $request->quantity;

        if ($requested > $available) {
            if ($available <= 0) {
                $item->delete();
                return $this->index($request);
            }
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

    // ── Private: Pack store ────────────────────────────────────────────────────

    /**
     * Handle POST /api/cart when pack_id is present.
     * Validates selections, checks stock, upserts a single cart row.
     */
    private function storePack(Request $request)
    {
        $request->validate([
            'pack_id'                           => 'required|integer|exists:packs,id',
            'pack_selections'                   => 'required|array',
            'pack_selections.*.pack_item_id'    => 'required|integer',
            'pack_selections.*.variant_id'      => 'nullable|integer',
        ]);

        $user = $request->user();

        $pack = Pack::with(['items.product', 'items.product.variants'])
            ->where('is_active', true)
            ->where('is_approved', true)
            ->findOrFail($request->pack_id);

        $selectionMap = collect($request->pack_selections)->keyBy('pack_item_id');

        // ── Validate each item's variant and stock ────────────────────────────
        foreach ($pack->items as $item) {
            $sel       = $selectionMap->get($item->id);
            $variantId = $sel['variant_id'] ?? null;

            if ($variantId) {
                $variant = ProductVariant::find($variantId);

                if (!$variant || $variant->product_id !== $item->product_id) {
                    return response()->json([
                        'success' => false,
                        'message' => "Invalid variant selected for \"{$item->product->name}\".",
                    ], 422);
                }

                if ($variant->stock < $item->quantity) {
                    return response()->json([
                        'success' => false,
                        'message' => "Not enough stock for \"{$item->product->name}\" ({$variant->label}). Only {$variant->stock} available.",
                    ], 422);
                }
            } else {
                // Product with no variant required
                if ($item->product->variants()->exists()) {
                    return response()->json([
                        'success' => false,
                        'message' => "Please select a variant for \"{$item->product->name}\".",
                    ], 422);
                }

                if ($item->product->stock < $item->quantity) {
                    return response()->json([
                        'success' => false,
                        'message' => "Not enough stock for \"{$item->product->name}\".",
                    ], 422);
                }
            }
        }

        // ── Upsert: one pack = one cart row ───────────────────────────────────
        // If the user already has this pack in cart, replace the selections.
        Cart::updateOrCreate(
            [
                'user_id' => $user->id,
                'pack_id' => $pack->id,
            ],
            [
                'product_id'           => null,
                'variant_id'           => null,
                'quantity'             => 1,
                'pack_price_snapshot'  => (float) $pack->pack_price,
                'pack_name'            => $pack->name,
                'pack_selections'      => $request->pack_selections,
            ]
        );

        return $this->index($request);
    }

    // ── Private: Formatters ────────────────────────────────────────────────────

    /**
     * Format a regular product cart row.
     * Original logic — completely unchanged.
     */
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
            // Discriminator so the frontend can tell this apart from a pack row
            'is_pack'         => false,
        ];
    }

    /**
     * Format a pack cart row.
     * Returns the pack_price_snapshot as the price — not the sum of items.
     */
    private function formatPackCartItem(Cart $item): array
    {
        $pack     = $item->pack;
        $imageUrl = null;

        if ($pack) {
            // Use the pack's image accessor if available
            $imageUrl = $pack->image_url ?? null;
        }

        return [
            'id'              => $item->id,
            'product_id'      => null,
            'variant_id'      => null,
            'pack_id'         => $item->pack_id,
            'pack_slug'       => $pack?->slug,
            'pack_selections' => $item->pack_selections ?? [],
            'name'            => $item->pack_name ?? $pack?->name ?? 'Bundle',
            'slug'            => $pack?->slug ?? '',
            'sku'             => null,
            'category'        => 'Bundle',
            'price'           => $item->pack_price_snapshot,
            'quantity'        => 1,           // packs are always qty 1
            'stock'           => 999,         // no stock limit at pack level
            'line_total'      => $item->pack_price_snapshot,
            'image_url'       => $imageUrl,
            'variant_label'   => null,
            'variant_options' => [],
            'is_pack'         => true,        // ← frontend uses this to render PackCartRow
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
<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Recommendation\InteractionTracker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FavoriteController extends Controller
{
    /**
     * GET /api/favorites
     * Returns all favorite items with variant-aware image URLs.
     */
    public function index(Request $request)
    {
        $favorites = Favorite::where('user_id', $request->user()->id)
            ->with([
                'product.images',
                'product.primaryImage',
                'variant.attributeOptions.attribute',
                'variant.images',
            ])
            ->get()
            ->map(fn($fav) => $this->formatFavorite($fav));

        return response()->json(['success' => true, 'data' => $favorites]);
    }

    /**
     * POST /api/favorites
     * Toggle favorite (add if not exists, remove if exists).
     */
    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'variant_id' => 'nullable|exists:product_variants,id',
        ]);

        $user      = $request->user();
        $productId = $request->product_id;
        $variantId = $request->variant_id ?? null;

// Block sellers from favoriting their own products
        $product = Product::findOrFail($productId);
        $this->ensureNotProductOwner($request, $product);

        $existing = Favorite::where('user_id', $user->id)
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->first();

        if ($existing) {
            $existing->delete();
            app(InteractionTracker::class)->recordFromRequest($request, 'favorite_remove', $product);
            return response()->json(['success' => true, 'data' => null, 'message' => __('messages.favorite.removed')]);
        }

        $fav = Favorite::create([
            'user_id'    => $user->id,
            'product_id' => $productId,
            'variant_id' => $variantId,
        ]);

        app(InteractionTracker::class)->recordFromRequest($request, 'favorite_add', $product);

        $fav->load([
            'product.images',
            'product.primaryImage',
            'variant.attributeOptions.attribute',
            'variant.images',
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->formatFavorite($fav),
            'message' => __('messages.favorite.added'),
        ]);
    }

    /**
     * DELETE /api/favorites
     * Remove a specific favorite by product_id (and optional variant_id).
     */
    public function destroy(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'variant_id' => 'nullable|exists:product_variants,id',
        ]);

        $query = Favorite::where('user_id', $request->user()->id)
            ->where('product_id', $request->product_id);

        if ($request->has('variant_id')) {
            $query->where('variant_id', $request->variant_id);
        }

        $query->delete();

        return response()->json(['success' => true, 'message' => __('messages.favorite.removed')]);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Format a single favorite with the correct variant-aware image URL.
     */
    private function formatFavorite(Favorite $fav): array
    {
        $product = $fav->product;
        $variant = $fav->variant;

        $price = $variant
            ? ($variant->price_override !== null
                ? (float) $variant->price_override
                : (float) $product->price)
            : (float) $product->price;

        $stock = $variant ? $variant->stock : $product->stock;

        $imageUrl = $this->resolveImageUrl($product, $variant);

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
            'id'              => $fav->id,
            'product_id'      => $product->id,
            'variant_id'      => $variant?->id,
            'name'            => $product->name,
            'slug'            => $product->slug,
            'price'           => $price,
            'stock'           => $stock,
            'image_url'       => $imageUrl,
            'variant_label'   => $variantLabel,
            'variant_options' => $variantOptions,
        ];
    }

    /** Main image of the variant's color group, else the product cover (sizes share images). */
    private function resolveImageUrl(Product $product, ?ProductVariant $variant): ?string
    {
        return \App\Services\ProductImages::thumbnailFor($product, $variant);
    }
}
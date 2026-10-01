<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\PromotionService;
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
            ->get();

        $promos    = app(PromotionService::class)->activePromotionsFor($favorites->pluck('product_id')->all());
        $favorites = $favorites->map(fn($fav) => $this->formatFavorite($fav, $promos[$fav->product_id] ?? null));

        return response()->json(['success' => true, 'data' => $favorites]);
    }

    /**
     * POST /api/favorites
     * Add a favorite. Idempotent: adding twice keeps one row (never removes —
     * removal is DELETE), so a storefront with a stale list can't flip it off.
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

        $fav = Favorite::firstOrCreate([
            'user_id'    => $user->id,
            'product_id' => $productId,
            'variant_id' => $variantId,
        ]);

        if ($fav->wasRecentlyCreated) {
            app(InteractionTracker::class)->recordFromRequest($request, 'favorite_add', $product);
        }

        $fav->load([
            'product.images',
            'product.primaryImage',
            'variant.attributeOptions.attribute',
            'variant.images',
        ]);

        return response()->json([
            'success'   => true,
            'favorited' => true,
            'data'      => $this->formatFavorite($fav, app(PromotionService::class)->getActivePromotionForProduct($fav->product_id)),
            'message'   => __('messages.favorite.added'),
        ]);
    }

    /**
     * DELETE /api/favorites
     * Remove a specific favorite by product_id (and optional variant_id).
     */
    public function destroy(Request $request, int $productId)
    {
        $request->validate(['variant_id' => 'nullable|integer']);

        // The product id comes from the URL (DELETE /favorites/{productId}).
        // With a variant_id only that variant's favorite goes, otherwise all of the product's.
        $query = Favorite::where('user_id', $request->user()->id)
            ->where('product_id', $productId)
            ->when($request->filled('variant_id'), fn ($q) => $q->where('variant_id', $request->variant_id));

        if ($query->delete() > 0 && ($product = Product::find($productId))) {
            app(InteractionTracker::class)->recordFromRequest($request, 'favorite_remove', $product);
        }

        return response()->json(['success' => true, 'favorited' => false, 'message' => __('messages.favorite.removed')]);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Format a single favorite with the correct variant-aware image URL.
     */
    private function formatFavorite(Favorite $fav, ?\App\Models\Promotion $promotion): array
    {
        $product = $fav->product;
        $variant = $fav->variant;

        $price   = PromotionService::basePrice($product, $variant);
        $pricing = app(PromotionService::class)->priceWith($product, $price, $variant?->id, $promotion);

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
        ] + $pricing;
    }

    /** Main image of the variant's color group, else the product cover (sizes share images). */
    private function resolveImageUrl(Product $product, ?ProductVariant $variant): ?string
    {
        return \App\Services\ProductImages::thumbnailFor($product, $variant);
    }
}
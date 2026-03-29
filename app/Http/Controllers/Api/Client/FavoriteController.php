<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\Product;
use App\Models\ProductVariant;
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
<<<<<<< HEAD
        $favorites = Favorite::with([
            'product.primaryImage',
            'product.category',
            'variant.attributeOptions.attribute',
            'variant.images',
        ])
            ->where('user_id', $request->user()->id)
            ->latest()
=======
        $favorites = Favorite::where('user_id', $request->user()->id)
            ->with([
                'product.images',
                'product.primaryImage',
                'variant.attributeOptions.attribute',
                'variant.images',
            ])
>>>>>>> b06fc03 (Abdou's changes)
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

<<<<<<< HEAD
        if ($request->filled('variant_id')) {
            $variant = ProductVariant::where('id', $request->variant_id)
                ->where('product_id', $request->product_id)
                ->where('is_active', true)
                ->first();
=======
        $user      = $request->user();
        $productId = $request->product_id;
        $variantId = $request->variant_id ?? null;
>>>>>>> b06fc03 (Abdou's changes)

        $existing = Favorite::where('user_id', $user->id)
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->first();

        if ($existing) {
            $existing->delete();
            return response()->json(['success' => true, 'data' => null, 'message' => 'Removed from favorites.']);
        }

        $fav = Favorite::create([
            'user_id'    => $user->id,
            'product_id' => $productId,
            'variant_id' => $variantId,
        ]);

        $fav->load([
            'product.images',
            'product.primaryImage',
            'variant.attributeOptions.attribute',
            'variant.images',
        ]);

        return response()->json([
<<<<<<< HEAD
            'success'   => true,
            'message'   => 'Added to favorites.',
            'favorited' => true,
            'data'      => $this->formatFavorite(
                $favorite->load([
                    'product.primaryImage',
                    'product.category',
                    'variant.attributeOptions.attribute',
                    'variant.images',
                ])
            ),
        ], 201);
=======
            'success' => true,
            'data'    => $this->formatFavorite($fav),
            'message' => 'Added to favorites.',
        ]);
>>>>>>> b06fc03 (Abdou's changes)
    }

    /**
     * DELETE /api/favorites
     * Remove a specific favorite by product_id (and optional variant_id).
     */
    public function destroy(Request $request)
    {
<<<<<<< HEAD
        $query = Favorite::where('user_id', $request->user()->id)
            ->where('product_id', $productId);
=======
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'variant_id' => 'nullable|exists:product_variants,id',
        ]);
>>>>>>> b06fc03 (Abdou's changes)

        $query = Favorite::where('user_id', $request->user()->id)
            ->where('product_id', $request->product_id);

        if ($request->has('variant_id')) {
            $query->where('variant_id', $request->variant_id);
        }

        $query->delete();

        return response()->json(['success' => true, 'message' => 'Removed from favorites.']);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Format a single favorite with the correct variant-aware image URL.
     */
    private function formatFavorite(Favorite $fav): array
    {
<<<<<<< HEAD
        $product  = $fav->product;
        $variant  = $fav->variant;

        // IMAGE PRIORITY: variant image → product primary image → null
        if ($variant && $variant->relationLoaded('images') && $variant->images->isNotEmpty()) {
            $imageUrl = rtrim(config('app.url'), '/') . '/storage/' . ltrim($variant->images->first()->image_path, '/');
        } elseif ($product->primaryImage) {
            $imageUrl = rtrim(config('app.url'), '/') . '/storage/' . ltrim($product->primaryImage->image_path, '/');
        } else {
            $imageUrl = null;
        }
=======
        $product = $fav->product;
        $variant = $fav->variant;
>>>>>>> b06fc03 (Abdou's changes)

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

    /**
     * Image priority:
     *  1. Variant's own images
     *  2. Product images with variant's color_option_id
     *  3. Product primary image
     *  4. null
     */
    private function resolveImageUrl(Product $product, ?ProductVariant $variant): ?string
    {
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
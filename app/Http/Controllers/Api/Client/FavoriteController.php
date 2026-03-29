<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    /* ── GET /api/favorites ── */
    public function index(Request $request)
    {
        $favorites = Favorite::with([
            'product.primaryImage',
            'product.category',
            'variant.attributeOptions.attribute',
            'variant.images',
        ])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get()
            ->map(fn($fav) => $this->formatFavorite($fav));

        return response()->json(['success' => true, 'data' => $favorites]);
    }

    /* ── POST /api/favorites ── */
    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'variant_id' => 'nullable|exists:product_variants,id',
        ]);

        if ($request->filled('variant_id')) {
            $variant = ProductVariant::where('id', $request->variant_id)
                ->where('product_id', $request->product_id)
                ->where('is_active', true)
                ->first();

            if (!$variant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected variant is unavailable.',
                ], 422);
            }
        }

        $favorite = Favorite::firstOrCreate([
            'user_id'    => $request->user()->id,
            'product_id' => $request->product_id,
            'variant_id' => $request->filled('variant_id') ? $request->variant_id : null,
        ]);

        return response()->json([
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
    }

    /* ── DELETE /api/favorites/{product_id} ── */
    public function destroy(Request $request, $productId)
    {
        $query = Favorite::where('user_id', $request->user()->id)
            ->where('product_id', $productId);

        if ($request->filled('variant_id')) {
            $query->where('variant_id', $request->variant_id);
        }

        $query->delete();

        return response()->json([
            'success'   => true,
            'message'   => 'Removed from favorites.',
            'favorited' => false,
        ]);
    }

    /* ── GET /api/favorites/check/{product_id} ── */
    public function check(Request $request, $productId)
    {
        $query = Favorite::where('user_id', $request->user()->id)
            ->where('product_id', $productId);

        if ($request->filled('variant_id')) {
            $query->where('variant_id', $request->variant_id);
        }

        $favorited = $query->exists();

        return response()->json(['success' => true, 'favorited' => $favorited]);
    }

    /* ── Private helper ── */
    private function formatFavorite(Favorite $fav): array
    {
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
            'id'              => $fav->id,
            'product_id'      => $product->id,
            'variant_id'      => $variant?->id,
            'variant_label'   => $variantLabel,
            'variant_options' => $variantOptions,
            'name'            => $product->name,
            'slug'            => $product->slug,
            'price'           => (float) ($variant?->price_override ?? $product->price),
            'stock'           => $variant ? $variant->stock : $product->stock,
            'image_url'       => $imageUrl,
            'category'        => $product->category?->name,
        ];
    }
}
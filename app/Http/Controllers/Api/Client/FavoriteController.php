<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\Product;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    /* ── GET /api/favorites ── */
    public function index(Request $request)
    {
        $favorites = Favorite::with(['product.primaryImage', 'product.category'])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get()
            ->map(fn($fav) => $this->formatFavorite($fav));

        return response()->json(['success' => true, 'data' => $favorites]);
    }

    /* ── POST /api/favorites ── */
    public function store(Request $request)
    {
        $request->validate(['product_id' => 'required|exists:products,id']);

        $favorite = Favorite::firstOrCreate([
            'user_id'    => $request->user()->id,
            'product_id' => $request->product_id,
        ]);

        return response()->json([
            'success'    => true,
            'message'    => 'Added to favorites.',
            'favorited'  => true,
            'data'       => $this->formatFavorite($favorite->load('product.primaryImage')),
        ], 201);
    }

    /* ── DELETE /api/favorites/{product_id} ── */
    public function destroy(Request $request, $productId)
    {
        Favorite::where('user_id', $request->user()->id)
            ->where('product_id', $productId)
            ->delete();

        return response()->json([
            'success'   => true,
            'message'   => 'Removed from favorites.',
            'favorited' => false,
        ]);
    }

    /* ── GET /api/favorites/check/{product_id} ── */
    public function check(Request $request, $productId)
    {
        $favorited = Favorite::where('user_id', $request->user()->id)
            ->where('product_id', $productId)
            ->exists();

        return response()->json(['success' => true, 'favorited' => $favorited]);
    }

    private function formatFavorite(Favorite $fav): array
    {
        $product  = $fav->product;
        $imgPath  = $product->primaryImage?->image_path;
        $imageUrl = $imgPath
            ? rtrim(config('app.url'), '/') . '/storage/' . ltrim($imgPath, '/')
            : null;

        return [
            'id'         => $fav->id,
            'product_id' => $product->id,
            'name'       => $product->name,
            'slug'       => $product->slug,
            'price'      => (float) $product->price,
            'stock'      => $product->stock,
            'image_url'  => $imageUrl,
            'category'   => $product->category?->name,
        ];
    }
}
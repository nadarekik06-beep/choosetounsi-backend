<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CartController extends Controller
{
    /* ── GET /api/cart ── */
    public function index(Request $request)
    {
        $items = Cart::with(['product.primaryImage', 'product.category'])
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
            'quantity'   => 'sometimes|integer|min:1|max:100',
        ]);

        $product = Product::findOrFail($request->product_id);

        // Availability check
        if (!$product->is_approved || !$product->is_active) {
            return response()->json(['success' => false, 'message' => 'Product is not available.'], 422);
        }

        $qty = $request->input('quantity', 1);

        if ($product->stock < $qty) {
            return response()->json([
                'success' => false,
                'message' => "Only {$product->stock} items in stock.",
            ], 422);
        }

        $cartItem = Cart::firstOrNew([
            'user_id'    => $request->user()->id,
            'product_id' => $product->id,
        ]);

        $newQty = ($cartItem->exists ? $cartItem->quantity : 0) + $qty;

        if ($newQty > $product->stock) {
            return response()->json([
                'success' => false,
                'message' => "Cannot add more — only {$product->stock} in stock.",
            ], 422);
        }

        $cartItem->quantity = $newQty;
        $cartItem->save();

        return response()->json([
            'success' => true,
            'message' => 'Added to cart.',
            'data'    => $this->formatItem($cartItem->fresh(['product.primaryImage'])),
        ], 201);
    }

    /* ── PUT /api/cart/{id} ── */
    public function update(Request $request, $id)
    {
        $request->validate(['quantity' => 'required|integer|min:1|max:100']);

        $cartItem = Cart::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if ($request->quantity > $cartItem->product->stock) {
            return response()->json([
                'success' => false,
                'message' => "Only {$cartItem->product->stock} in stock.",
            ], 422);
        }

        $cartItem->update(['quantity' => $request->quantity]);

        return response()->json([
            'success' => true,
            'message' => 'Cart updated.',
            'data'    => $this->formatItem($cartItem->fresh(['product.primaryImage'])),
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
        $imgPath  = $product->primaryImage?->image_path;
        $imageUrl = $imgPath
            ? rtrim(config('app.url'), '/') . '/storage/' . ltrim($imgPath, '/')
            : null;

        return [
            'id'         => $item->id,
            'product_id' => $product->id,
            'name'       => $product->name,
            'slug'       => $product->slug,
            'sku'        => $product->sku,
            'price'      => (float) $product->price,
            'quantity'   => $item->quantity,
            'line_total' => round((float) $product->price * $item->quantity, 3),
            'stock'      => $product->stock,
            'image_url'  => $imageUrl,
            'category'   => $product->category?->name,
        ];
    }
}
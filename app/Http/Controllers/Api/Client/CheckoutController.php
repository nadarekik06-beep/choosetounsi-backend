<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    /**
     * POST /api/checkout
     *
     * Creates an order from the user's cart.
     * Validates per-variant (or per-product) stock before committing.
     * ── UNCHANGED from original ──
     */
    public function store(Request $request)
    {
        $request->validate([
            'wilaya'  => 'required|string|max:255',
            'address' => 'required|string|max:500',
            'phone'   => 'required|string|max:30',
            'notes'   => 'nullable|string|max:1000',
        ]);

        $user = $request->user();

        // Load full cart with variant data
        $cartItems = Cart::with([
            'product',
            'variant.attributeOptions.attribute',
        ])->where('user_id', $user->id)->get();

        if ($cartItems->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Your cart is empty.',
            ], 422);
        }

        // ── Pre-flight stock check ──────────────────────────────────────────
        foreach ($cartItems as $item) {
            $product = $item->product;

            if (!$product || !$product->is_approved || !$product->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => "\"$product->name\" is no longer available.",
                ], 422);
            }

            $stockPool = $item->variant
                ? $item->variant->stock
                : $product->stock;

            if ($stockPool < $item->quantity) {
                $label = $item->variant
                    ? "\"{$product->name}\" ({$item->variant->label})"
                    : "\"{$product->name}\"";

                return response()->json([
                    'success' => false,
                    'message' => "{$label} only has {$stockPool} item(s) in stock but {$item->quantity} were requested.",
                ], 422);
            }
        }

        // ── Create order inside a transaction ──────────────────────────────
        DB::beginTransaction();
        try {
            $total = $cartItems->sum(function ($item) {
                $price = $item->variant
                    ? (float) ($item->variant->price_override ?? $item->product->price)
                    : (float) $item->product->price;
                return round($price * $item->quantity, 2);
            });

            $order = Order::create([
                'user_id'        => $user->id,
                'order_number'   => 'ORD-' . strtoupper(Str::random(8)),
                'status'         => 'pending',
                'payment_status' => 'unpaid',
                'total_amount'   => $total,
                'wilaya'         => $request->wilaya,
                'address'        => $request->address,
                'phone'          => $request->phone,
                'notes'          => $request->notes ?? null,
            ]);

            foreach ($cartItems as $item) {
                $product   = $item->product;
                $variant   = $item->variant;
                $unitPrice = $variant
                    ? (float) ($variant->price_override ?? $product->price)
                    : (float) $product->price;

                $variantLabel = $variant
                    ? $variant->attributeOptions->pluck('value')->join(' / ')
                    : null;

                OrderItem::create([
                    'order_id'      => $order->id,
                    'product_id'    => $product->id,
                    'variant_id'    => $variant?->id,
                    'variant_label' => $variantLabel,
                    'product_name'  => $product->name,
                    'quantity'      => $item->quantity,
                    'unit_price'    => $unitPrice,
                    'price'         => $unitPrice,
                    'total'         => round($unitPrice * $item->quantity, 2),
                ]);

                if ($variant) {
                    ProductVariant::where('id', $variant->id)
                        ->decrement('stock', $item->quantity);
                } else {
                    $product->decrement('stock', $item->quantity);
                }
            }

            Cart::where('user_id', $user->id)->delete();

            DB::commit();

            return response()->json([
                'success'      => true,
                'message'      => 'Order placed successfully!',
                'order_number' => $order->order_number,
                'order_id'     => $order->id,
                'total'        => $total,
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error('Checkout failed: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to place order. Please try again.',
            ], 500);
        }
    }

    /**
     * POST /api/checkout/buy-now
     *
     * Creates a single-item order directly — does NOT touch the cart.
     * Accepts: product_id, variant_id (optional), quantity, wilaya, address, phone, notes.
     */
    public function buyNow(Request $request)
    {
        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'variant_id' => 'nullable|integer|exists:product_variants,id',
            'quantity'   => 'required|integer|min:1|max:100',
            'wilaya'     => 'required|string|max:255',
            'address'    => 'required|string|max:500',
            'phone'      => 'required|string|max:30',
            'notes'      => 'nullable|string|max:1000',
        ]);

        $user     = $request->user();
        $quantity = (int) $request->quantity;

        // ── Load product ────────────────────────────────────────────────────
        $product = Product::find($request->product_id);

        if (!$product || !$product->is_approved || !$product->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'This product is no longer available.',
            ], 422);
        }

        // ── Load variant if provided ────────────────────────────────────────
        $variant = null;
        if ($request->filled('variant_id')) {
            $variant = ProductVariant::with('attributeOptions.attribute')
                ->where('id', $request->variant_id)
                ->where('product_id', $product->id)   // security: variant must belong to product
                ->first();

            if (!$variant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected variant not found.',
                ], 422);
            }

            if (!$variant->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected variant is no longer available.',
                ], 422);
            }
        }

        // ── Stock check ─────────────────────────────────────────────────────
        $stockPool = $variant ? $variant->stock : $product->stock;

        if ($stockPool < $quantity) {
            $label = $variant
                ? "\"{$product->name}\" ({$variant->label})"
                : "\"{$product->name}\"";

            return response()->json([
                'success' => false,
                'message' => "{$label} only has {$stockPool} item(s) in stock.",
            ], 422);
        }

        // ── Effective unit price ────────────────────────────────────────────
        $unitPrice = $variant
            ? (float) ($variant->price_override ?? $product->price)
            : (float) $product->price;

        $total = round($unitPrice * $quantity, 2);

        // ── Variant label snapshot ──────────────────────────────────────────
        $variantLabel = $variant
            ? $variant->attributeOptions->pluck('value')->join(' / ')
            : null;

        // ── Create order in transaction ─────────────────────────────────────
        DB::beginTransaction();
        try {
            $order = Order::create([
                'user_id'        => $user->id,
                'order_number'   => 'ORD-' . strtoupper(Str::random(8)),
                'status'         => 'pending',
                'payment_status' => 'unpaid',
                'total_amount'   => $total,
                'wilaya'         => $request->wilaya,
                'address'        => $request->address,
                'phone'          => $request->phone,
                'notes'          => $request->notes ?? null,
            ]);

            OrderItem::create([
                'order_id'      => $order->id,
                'product_id'    => $product->id,
                'variant_id'    => $variant?->id,
                'variant_label' => $variantLabel,
                'product_name'  => $product->name,
                'quantity'      => $quantity,
                'unit_price'    => $unitPrice,
                'price'         => $unitPrice,
                'total'         => $total,
            ]);

            // Decrement stock
            if ($variant) {
                ProductVariant::where('id', $variant->id)->decrement('stock', $quantity);
            } else {
                $product->decrement('stock', $quantity);
            }

            DB::commit();

            return response()->json([
                'success'      => true,
                'message'      => 'Order placed successfully!',
                'order_number' => $order->order_number,
                'order_id'     => $order->id,
                'total'        => $total,
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error('Buy Now checkout failed: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to place order. Please try again.',
            ], 500);
        }
    }
}
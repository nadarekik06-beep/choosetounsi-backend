<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
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

            // Stock pool is the variant's stock when a variant is chosen
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
                return round($price * $item->quantity, 3);
            });

            $order = Order::create([
                'user_id'        => $user->id,
                'order_number'   => 'ORD-' . strtoupper(Str::random(8)),
                'status'         => 'pending',
                'payment_status' => 'pending',
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

                // Build variant label snapshot (persisted for historical records)
                $variantLabel = $variant
                    ? $variant->attributeOptions->pluck('value')->join(' / ')
                    : null;

                OrderItem::create([
                    'order_id'     => $order->id,
                    'product_id'   => $product->id,
                    'variant_id'   => $variant?->id,
                    'variant_label'=> $variantLabel,
                    'product_name' => $product->name,
                    'quantity'     => $item->quantity,
                    'unit_price'   => $unitPrice,
                    'total'        => round($unitPrice * $item->quantity, 3),
                ]);

                // ── Decrement stock ─────────────────────────────────────────
                if ($variant) {
                    ProductVariant::where('id', $variant->id)
                        ->decrement('stock', $item->quantity);
                } else {
                    $product->decrement('stock', $item->quantity);
                }
            }

            // Clear the user's cart
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
            return response()->json([
                'success' => false,
                'message' => 'Failed to place order. Please try again.',
            ], 500);
        }
    }
}
<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CheckoutController extends Controller
{
    /**
     * POST /api/checkout
     * Validates cart, creates order + items, decrements stock, clears cart.
     */
    public function store(Request $request)
    {
        $request->validate([
            'wilaya'  => 'required|string|max:100',
            'address' => 'required|string|max:500',
            'phone'   => 'required|string|max:20',
            'notes'   => 'nullable|string|max:1000',
        ]);

        $user = $request->user();

        // ── Load cart ──────────────────────────────────────────────────────
        $cartItems = Cart::with('product')
            ->where('user_id', $user->id)
            ->get();

        if ($cartItems->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Your cart is empty.',
            ], 422);
        }

        // ── Validate stock ─────────────────────────────────────────────────
        $errors = [];
        foreach ($cartItems as $item) {
            $product = $item->product;

            if (!$product || !$product->is_approved || !$product->is_active) {
                $errors[] = ($product ? $product->name : 'A product') . ' is no longer available.';
                continue;
            }

            if ($item->quantity > $product->stock) {
                $errors[] = $product->name . ': only ' . $product->stock . ' in stock (you requested ' . $item->quantity . ').';
            }
        }

        if ($errors) {
            return response()->json([
                'success' => false,
                'message' => 'Some items are unavailable.',
                'errors'  => $errors,
            ], 422);
        }

        // ── Create order in a transaction ──────────────────────────────────
        try {
            $order = DB::transaction(function () use ($cartItems, $user, $request) {

                $total = 0;

                // Create order
                $order = Order::create([
                    'user_id'        => $user->id,
                    'total_amount'   => 0,
                    'status'         => 'pending',
                    'payment_status' => 'unpaid',
                    'wilaya'         => $request->wilaya,
                    'address'        => $request->address,
                    'phone'          => $request->phone,
                    'notes'          => $request->notes,
                ]);

                foreach ($cartItems as $item) {
                    $product   = $item->product;
                    $unitPrice = (float) $product->price;
                    $lineTotal = round($unitPrice * $item->quantity, 3);
                    $total    += $lineTotal;

                    OrderItem::create([
                        'order_id'     => $order->id,
                        'product_id'   => $product->id,
                        'product_name' => $product->name,
                        'quantity'     => $item->quantity,
                        'unit_price'   => $unitPrice,
                        'price'        => $unitPrice,
                        'total'        => $lineTotal,
                    ]);

                    // Decrement stock
                    $product->decrement('stock', $item->quantity);
                }

                $order->update(['total_amount' => round($total, 3)]);

                // Clear cart
                Cart::where('user_id', $user->id)->delete();

                return $order;
            });

            return response()->json([
                'success'      => true,
                'message'      => 'Order placed successfully!',
                'order_number' => $order->order_number,
                'order_id'     => $order->id,
                'total'        => (float) $order->total_amount,
            ], 201);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Checkout failed. Please try again.',
            ], 500);
        }
    }
}
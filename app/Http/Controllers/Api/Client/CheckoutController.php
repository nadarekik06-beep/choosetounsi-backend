<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerOrder;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    public function __construct(private WalletService $walletService) {}

    /**
     * POST /api/checkout
     *
     * Creates an order from the user's cart.
     * Cart items are grouped by seller — one SellerOrder row per seller.
     * Each OrderItem is linked to its seller's sub-order via seller_order_id.
     */
    public function store(Request $request)
    {
        $request->validate([
            'wilaya'         => 'required|string|max:255',
            'address'        => 'required|string|max:500',
            'phone'          => 'required|string|max:30',
            'notes'          => 'nullable|string|max:1000',
            'payment_method' => 'nullable|string|in:cod,card,d17,wallet',
        ]);

        $user = $request->user();

        // Load full cart with variant + product data including seller_id
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

        $paymentMethod = $request->payment_method ?? 'cod';

        // ── Pre-flight stock + availability check ────────────────────────────
        foreach ($cartItems as $item) {
            $product = $item->product;

            if (!$product || !$product->is_approved || !$product->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => "\"$product->name\" is no longer available.",
                ], 422);
            }

            // Block sellers from purchasing their own products
            $this->ensureNotProductOwner($request, $product);

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

        // ── Detect which column holds the seller foreign key ─────────────────
        $sellerCol = $this->getSellerCol();

        // ── Compute total (used for wallet pre-check) ────────────────────────
        $total = $this->calculateCartTotal($cartItems);

        // ── Wallet pre-check (before touching the DB) ────────────────────────
        if ($paymentMethod === 'wallet') {
            if ((float) $user->wallet_balance < $total) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient wallet balance.',
                    'data'    => [
                        'wallet_balance' => (float) $user->wallet_balance,
                        'required'       => $total,
                    ],
                ], 422);
            }
        }

        DB::beginTransaction();
        try {
            // ── Step 1: Create the parent order ──────────────────────────────
            $order = Order::create([
                'user_id'        => $user->id,
                'order_number'   => 'ORD-' . strtoupper(Str::random(8)),
                'status'         => 'pending',
                'payment_status' => $paymentMethod === 'wallet' ? 'paid' : 'unpaid',
                'payment_method' => $paymentMethod,
                'total_amount'   => $total,
                'wilaya'         => $request->wilaya,
                'address'        => $request->address,
                'phone'          => $request->phone,
                'notes'          => $request->notes ?? null,
            ]);

            // ── Step 2: Group cart items by seller ────────────────────────────
            $groupedBySeller = $cartItems->groupBy(function ($item) use ($sellerCol) {
                return $item->product->{$sellerCol};
            });

            // ── Step 3: Create one SellerOrder per seller ─────────────────────
            foreach ($groupedBySeller as $sellerId => $sellerItems) {

                $sellerSubtotal = $sellerItems->sum(function ($item) {
                    $price = $item->variant
                        ? (float) ($item->variant->price_override ?? $item->product->price)
                        : (float) $item->product->price;
                    return round($price * $item->quantity, 3);
                });

                $sellerOrder = SellerOrder::create([
                    'order_id'       => $order->id,
                    'seller_id'      => $sellerId,
                    'status'         => 'pending',
                    'payment_status' => $paymentMethod === 'wallet' ? 'paid' : 'unpaid',
                    'subtotal'       => $sellerSubtotal,
                ]);

                // ── Step 4: Create OrderItems linked to this SellerOrder ──────
                foreach ($sellerItems as $item) {
                    $product   = $item->product;
                    $variant   = $item->variant;
                    $unitPrice = $variant
                        ? (float) ($variant->price_override ?? $product->price)
                        : (float) $product->price;

                    // Build variant label from attributeOptions (authoritative snapshot)
                    $variantLabel = $variant
                        ? $variant->attributeOptions->pluck('value')->join(' / ')
                        : null;

                    OrderItem::create([
                        'order_id'        => $order->id,
                        'seller_order_id' => $sellerOrder->id,
                        'product_id'      => $product->id,
                        'variant_id'      => $variant?->id,
                        'variant_label'   => $variantLabel,
                        'product_name'    => $product->name,
                        'quantity'        => $item->quantity,
                        'unit_price'      => $unitPrice,
                        'price'           => $unitPrice,
                        'total'           => round($unitPrice * $item->quantity, 3),
                    ]);

                    // ── Step 5: Decrement stock ───────────────────────────────
                    if ($variant) {
                        ProductVariant::where('id', $variant->id)
                            ->decrement('stock', $item->quantity);
                    } else {
                        $product->decrement('stock', $item->quantity);
                    }
                }
            }

            // ── Step 6: Deduct wallet balance if applicable ───────────────────
            if ($paymentMethod === 'wallet') {
                $this->walletService->deductForOrder($user, $order);
            }

            // ── Step 7: Clear the cart ────────────────────────────────────────
            Cart::where('user_id', $user->id)->delete();

            DB::commit();

            return response()->json([
                'success'        => true,
                'message'        => 'Order placed successfully!',
                'order_number'   => $order->order_number,
                'order_id'       => $order->id,
                'total'          => $total,
                'seller_count'   => $groupedBySeller->count(),
                'needs_payment'  => $paymentMethod === 'card',
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[Checkout] store failed: ' . $e->getMessage(), [
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
     * A SellerOrder is also created for the single seller involved.
     */
    public function buyNow(Request $request)
    {
        $request->validate([
            'product_id'     => 'required|integer|exists:products,id',
            'variant_id'     => 'nullable|integer|exists:product_variants,id',
            'quantity'       => 'required|integer|min:1|max:100',
            'wilaya'         => 'required|string|max:255',
            'address'        => 'required|string|max:500',
            'phone'          => 'required|string|max:30',
            'notes'          => 'nullable|string|max:1000',
            'payment_method' => 'nullable|string|in:cod,card,d17,wallet',
        ]);

        $user          = $request->user();
        $quantity      = (int) $request->quantity;
        $paymentMethod = $request->payment_method ?? 'cod';

        // ── Load product ──────────────────────────────────────────────────────
        $product = Product::find($request->product_id);

        if (!$product || !$product->is_approved || !$product->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'This product is no longer available.',
            ], 422);
        }

        // ── Load variant if provided ──────────────────────────────────────────
        $variant = null;
        if ($request->filled('variant_id')) {
            $variant = ProductVariant::with('attributeOptions.attribute')
                ->where('id', $request->variant_id)
                ->where('product_id', $product->id)
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

        // ── Stock check ───────────────────────────────────────────────────────
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

        // ── Effective unit price ──────────────────────────────────────────────
        $unitPrice = $variant
            ? (float) ($variant->price_override ?? $product->price)
            : (float) $product->price;

        $subtotal = round($unitPrice * $quantity, 3);
        $total    = round($subtotal + 8, 3); // +8 DT shipping

        // ── Variant label snapshot (from attributeOptions) ────────────────────
        $variantLabel = $variant
            ? $variant->attributeOptions->pluck('value')->join(' / ')
            : null;

        // ── Detect seller column ──────────────────────────────────────────────
        $sellerCol = $this->getSellerCol();
        $sellerId  = $product->{$sellerCol};

        // ── Wallet pre-check ──────────────────────────────────────────────────
        if ($paymentMethod === 'wallet') {
            if ((float) $user->wallet_balance < $total) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient wallet balance.',
                    'data'    => [
                        'wallet_balance' => (float) $user->wallet_balance,
                        'required'       => $total,
                    ],
                ], 422);
            }
        }

        DB::beginTransaction();
        try {
            // Create parent order
            $order = Order::create([
                'user_id'        => $user->id,
                'order_number'   => 'ORD-' . strtoupper(Str::random(8)),
                'status'         => 'pending',
                'payment_status' => $paymentMethod === 'wallet' ? 'paid' : 'unpaid',
                'payment_method' => $paymentMethod,
                'total_amount'   => $total,
                'wilaya'         => $request->wilaya,
                'address'        => $request->address,
                'phone'          => $request->phone,
                'notes'          => $request->notes ?? null,
            ]);

            // Create the single seller's sub-order
            $sellerOrder = SellerOrder::create([
                'order_id'       => $order->id,
                'seller_id'      => $sellerId,
                'status'         => 'pending',
                'payment_status' => $paymentMethod === 'wallet' ? 'paid' : 'unpaid',
                'subtotal'       => $subtotal,
            ]);

            // Create the order item linked to the seller's sub-order
            OrderItem::create([
                'order_id'        => $order->id,
                'seller_order_id' => $sellerOrder->id,
                'product_id'      => $product->id,
                'variant_id'      => $variant?->id,
                'variant_label'   => $variantLabel,
                'product_name'    => $product->name,
                'quantity'        => $quantity,
                'unit_price'      => $unitPrice,
                'price'           => $unitPrice,
                'total'           => $subtotal,
            ]);

            // Decrement stock
            if ($variant) {
                ProductVariant::where('id', $variant->id)->decrement('stock', $quantity);
            } else {
                $product->decrement('stock', $quantity);
            }

            // Deduct wallet balance if applicable
            if ($paymentMethod === 'wallet') {
                $this->walletService->deductForOrder($user, $order);
            }

            DB::commit();

            return response()->json([
                'success'       => true,
                'message'       => 'Order placed successfully!',
                'order_number'  => $order->order_number,
                'order_id'      => $order->id,
                'total'         => $total,
                'needs_payment' => $paymentMethod === 'card',
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[Checkout] buyNow failed: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to place order. Please try again.',
            ], 500);
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Compute cart total (subtotal + 8 DT shipping).
     * Uses price_override when a variant is present, falls back to product price.
     */
    private function calculateCartTotal($cartItems): float
    {
        $subtotal = $cartItems->sum(function ($item) {
            $price = $item->variant
                ? (float) ($item->variant->price_override ?? $item->product->price)
                : (float) $item->product->price;
            return round($price * $item->quantity, 3);
        });

        return round($subtotal + 8, 3); // +8 DT shipping
    }

    /**
     * Detect whether the products table uses `seller_id` or the legacy `user_id`
     * as the seller foreign key. Cached statically for the request lifetime.
     */
    private function getSellerCol(): string
    {
        static $col = null;
        if ($col) return $col;

        $columns  = DB::select('SHOW COLUMNS FROM products');
        $colNames = array_map(fn($c) => $c->Field, $columns);
        $col      = in_array('seller_id', $colNames) ? 'seller_id' : 'user_id';

        return $col;
    }
}
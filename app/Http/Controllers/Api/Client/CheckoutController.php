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
use App\Services\StockAlertService;          // ← NEW
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    public function __construct(
        private WalletService     $walletService,
        private StockAlertService $stockAlertService,   // ← NEW (auto-injected by Laravel DI)
    ) {}

    /**
     * POST /api/checkout
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

        $sellerCol = $this->getSellerCol();
        $total     = $this->calculateCartTotal($cartItems);

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

        // ── Collect decremented models for post-commit stock checks ──────────
        // We cannot check stock INSIDE the transaction because updateQuietly
        // (used by StockAlertService to stamp notification timestamps) would
        // participate in the same transaction and roll back on checkout failure.
        // Instead, we collect the models and check AFTER commit.
        $decrementedVariants = [];   // [ ['variant' => $v, 'product' => $p] ]
        $decrementedProducts = [];   // [ $p ]

        DB::beginTransaction();
        try {
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

            $groupedBySeller = $cartItems->groupBy(function ($item) use ($sellerCol) {
                return $item->product->{$sellerCol};
            });

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

                foreach ($sellerItems as $item) {
                    $product   = $item->product;
                    $variant   = $item->variant;
                    $unitPrice = $variant
                        ? (float) ($variant->price_override ?? $product->price)
                        : (float) $product->price;

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

                    // ── Decrement stock ───────────────────────────────────────
                    if ($variant) {
                        ProductVariant::where('id', $variant->id)
                            ->decrement('stock', $item->quantity);

                        // Queue for post-commit check
                        $decrementedVariants[] = [
                            'variant_id' => $variant->id,
                            'product'    => $product,
                        ];
                    } else {
                        $product->decrement('stock', $item->quantity);

                        // Queue for post-commit check
                        $decrementedProducts[] = $product->id;
                    }
                }
            }

            if ($paymentMethod === 'wallet') {
                $this->walletService->deductForOrder($user, $order);
            }

            Cart::where('user_id', $user->id)->delete();

            DB::commit();

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

        // ── Post-commit: fire stock alerts ────────────────────────────────────
        // Now outside the transaction — StockAlertService's updateQuietly calls
        // won't interfere with order creation.
        $this->fireStockAlerts($decrementedVariants, $decrementedProducts);

        return response()->json([
            'success'        => true,
            'message'        => 'Order placed successfully!',
            'order_number'   => $order->order_number,
            'order_id'       => $order->id,
            'total'          => $total,
            'seller_count'   => $groupedBySeller->count(),
            'needs_payment'  => $paymentMethod === 'card',
        ], 201);
    }

    /**
     * POST /api/checkout/buy-now
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

        $product = Product::find($request->product_id);

        if (!$product || !$product->is_approved || !$product->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'This product is no longer available.',
            ], 422);
        }

        $variant = null;
        if ($request->filled('variant_id')) {
            $variant = ProductVariant::with('attributeOptions.attribute')
                ->where('id', $request->variant_id)
                ->where('product_id', $product->id)
                ->first();

            if (!$variant || !$variant->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected variant is not available.',
                ], 422);
            }
        }

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

        $unitPrice    = $variant
            ? (float) ($variant->price_override ?? $product->price)
            : (float) $product->price;
        $subtotal     = round($unitPrice * $quantity, 3);
        $total        = round($subtotal + 8, 3);
        $variantLabel = $variant
            ? $variant->attributeOptions->pluck('value')->join(' / ')
            : null;
        $sellerCol    = $this->getSellerCol();
        $sellerId     = $product->{$sellerCol};

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

        $decrementedVariants = [];
        $decrementedProducts = [];

        DB::beginTransaction();
        try {
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

            $sellerOrder = SellerOrder::create([
                'order_id'       => $order->id,
                'seller_id'      => $sellerId,
                'status'         => 'pending',
                'payment_status' => $paymentMethod === 'wallet' ? 'paid' : 'unpaid',
                'subtotal'       => $subtotal,
            ]);

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
                $decrementedVariants[] = [
                    'variant_id' => $variant->id,
                    'product'    => $product,
                ];
            } else {
                $product->decrement('stock', $quantity);
                $decrementedProducts[] = $product->id;
            }

            if ($paymentMethod === 'wallet') {
                $this->walletService->deductForOrder($user, $order);
            }

            DB::commit();

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

        // Post-commit stock alerts
        $this->fireStockAlerts($decrementedVariants, $decrementedProducts);

        return response()->json([
            'success'       => true,
            'message'       => 'Order placed successfully!',
            'order_number'  => $order->order_number,
            'order_id'      => $order->id,
            'total'         => $total,
            'needs_payment' => $paymentMethod === 'card',
        ], 201);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Fire stock alerts after the transaction is committed.
     *
     * We fresh()-load each model to get the post-decrement DB values —
     * the in-memory models still hold the pre-decrement stock values
     * because raw ->decrement() doesn't update the model instance.
     *
     * @param array $decrementedVariants  [ ['variant_id' => int, 'product' => Product] ]
     * @param array $decrementedProducts  [ int $productId ]
     */
    private function fireStockAlerts(array $decrementedVariants, array $decrementedProducts): void
    {
        try {
            foreach ($decrementedVariants as $entry) {
                $freshVariant = ProductVariant::find($entry['variant_id']);
                if ($freshVariant) {
                    // Load attributeOptions so LowStockNotification can build the label
                    // without an extra query inside the notification class.
                    $freshVariant->load('attributeOptions.attribute');
                    $this->stockAlertService->checkVariant($freshVariant, $entry['product']);
                }
            }

            foreach ($decrementedProducts as $productId) {
                $freshProduct = Product::with('seller')->find($productId);
                if ($freshProduct) {
                    $this->stockAlertService->checkProduct($freshProduct);
                }
            }
        } catch (\Throwable $e) {
            // NEVER let alert failures surface to the user — order is already placed.
            Log::error('[Checkout] fireStockAlerts failed: ' . $e->getMessage());
        }
    }

    private function calculateCartTotal($cartItems): float
    {
        $subtotal = $cartItems->sum(function ($item) {
            $price = $item->variant
                ? (float) ($item->variant->price_override ?? $item->product->price)
                : (float) $item->product->price;
            return round($price * $item->quantity, 3);
        });

        return round($subtotal + 8, 3);
    }

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
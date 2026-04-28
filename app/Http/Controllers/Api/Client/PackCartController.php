<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Pack;
use App\Models\PackCart;
use App\Models\PackItem;
use App\Models\ProductVariant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SellerOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PackCartController extends Controller
{
    // ── GET /api/pack-cart ────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $entries = PackCart::where('user_id', $request->user()->id)
            ->with([
                'pack.items.product.primaryImage',
                'pack.items.product.variants.attributeOptions.attribute',
            ])
            ->get()
            ->map(fn($entry) => $this->formatEntry($entry));

        return response()->json(['success' => true, 'data' => $entries]);
    }

    // ── POST /api/pack-cart ───────────────────────────────────────────────────
    // Payload: { pack_id, selected_variants: [{pack_item_id, variant_id}] }

    public function store(Request $request)
    {
        $request->validate([
            'pack_id'                        => 'required|integer|exists:packs,id',
            'selected_variants'              => 'required|array',
            'selected_variants.*.pack_item_id' => 'required|integer',
            'selected_variants.*.variant_id'   => 'nullable|integer',
        ]);

        $user   = $request->user();
        $pack   = Pack::with([
            'items.product',
            'items.product.variants',
        ])->where('is_active', true)
          ->where('is_approved', true)
          ->findOrFail($request->pack_id);

        // ── Validate each selected variant belongs to the right item ──────────
        $selectionMap = collect($request->selected_variants)
            ->keyBy('pack_item_id');

        foreach ($pack->items as $item) {
            $selection = $selectionMap->get($item->id);
            $variantId = $selection['variant_id'] ?? null;

            if ($variantId) {
                $variant = ProductVariant::find($variantId);
                if (!$variant || $variant->product_id !== $item->product_id) {
                    return response()->json([
                        'success' => false,
                        'message' => "Invalid variant selected for \"{$item->product->name}\".",
                    ], 422);
                }

                // Check stock
                $needed = $item->quantity;
                if ($variant->stock < $needed) {
                    return response()->json([
                        'success' => false,
                        'message' => "Not enough stock for \"{$item->product->name}\" ({$variant->label}). Only {$variant->stock} available.",
                    ], 422);
                }
            } else {
                // Simple product (no variants) — check product stock
                if ($item->product->stock < $item->quantity) {
                    return response()->json([
                        'success' => false,
                        'message' => "Not enough stock for \"{$item->product->name}\".",
                    ], 422);
                }
            }
        }

        // ── Upsert pack cart entry ────────────────────────────────────────────
        PackCart::updateOrCreate(
            ['user_id' => $user->id, 'pack_id' => $pack->id],
            ['selected_variants' => $request->selected_variants]
        );

        return response()->json([
            'success' => true,
            'message' => 'Pack added to cart!',
        ]);
    }

    // ── DELETE /api/pack-cart/{packId} ────────────────────────────────────────

    public function destroy(Request $request, int $packId)
    {
        PackCart::where('user_id', $request->user()->id)
            ->where('pack_id', $packId)
            ->delete();

        return response()->json(['success' => true, 'message' => 'Pack removed from cart.']);
    }

    // ── POST /api/pack-cart/checkout ──────────────────────────────────────────

    public function checkout(Request $request)
    {
        $request->validate([
            'pack_id'        => 'required|integer|exists:packs,id',
            'wilaya'         => 'required|string|max:255',
            'address'        => 'required|string|max:500',
            'phone'          => 'required|string|max:30',
            'notes'          => 'nullable|string|max:1000',
            'payment_method' => 'nullable|string|in:cod,card,d17,wallet',
        ]);

        $user  = $request->user();
        $pack  = Pack::with([
            'items.product',
            'items.product.variants.attributeOptions.attribute',
        ])->findOrFail($request->pack_id);

        // Load the cart entry
        $cartEntry = PackCart::where('user_id', $user->id)
            ->where('pack_id', $pack->id)
            ->firstOrFail();

        $selectionMap    = collect($cartEntry->selected_variants)->keyBy('pack_item_id');
        $paymentMethod   = $request->payment_method ?? 'cod';
        $total           = 0;

        // ── Pre-flight validation ─────────────────────────────────────────────
        foreach ($pack->items as $item) {
            $sel       = $selectionMap->get($item->id);
            $variantId = $sel['variant_id'] ?? null;

            if ($variantId) {
                $variant = ProductVariant::find($variantId);
                if (!$variant || $variant->stock < $item->quantity) {
                    return response()->json([
                        'success' => false,
                        'message' => "Insufficient stock for \"{$item->product->name}\".",
                    ], 422);
                }
                $unitPrice = (float) ($variant->price_override ?? $item->product->price);
            } else {
                if ($item->product->stock < $item->quantity) {
                    return response()->json([
                        'success' => false,
                        'message' => "Insufficient stock for \"{$item->product->name}\".",
                    ], 422);
                }
                $unitPrice = (float) $item->product->price;
            }

            $total += $unitPrice * $item->quantity;
        }

        // Use pack price instead of sum of items
        $total = round((float) $pack->pack_price + 8, 3);

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

            // Group pack items by seller
            $grouped = $pack->items->groupBy(fn($item) => $item->product->seller_id);

            foreach ($grouped as $sellerId => $sellerItems) {
                $sellerSubtotal = round(
                    (float) $pack->pack_price * ($sellerItems->count() / $pack->items->count()),
                    3
                );

                $sellerOrder = SellerOrder::create([
                    'order_id'       => $order->id,
                    'seller_id'      => $sellerId,
                    'status'         => 'pending',
                    'payment_status' => $paymentMethod === 'wallet' ? 'paid' : 'unpaid',
                    'subtotal'       => $sellerSubtotal,
                ]);

                foreach ($sellerItems as $item) {
                    $sel       = $selectionMap->get($item->id);
                    $variantId = $sel['variant_id'] ?? null;
                    $variant   = $variantId ? ProductVariant::with('attributeOptions.attribute')->find($variantId) : null;
                    $unitPrice = $variant
                        ? (float) ($variant->price_override ?? $item->product->price)
                        : (float) $item->product->price;
                    $variantLabel = $variant
                        ? $variant->attributeOptions->pluck('value')->join(' / ')
                        : null;

                    OrderItem::create([
                        'order_id'        => $order->id,
                        'seller_order_id' => $sellerOrder->id,
                        'product_id'      => $item->product_id,
                        'variant_id'      => $variantId,
                        'variant_label'   => $variantLabel,
                        'product_name'    => $item->product->name . ' (Pack: ' . $pack->name . ')',
                        'quantity'        => $item->quantity,
                        'unit_price'      => $unitPrice,
                        'price'           => $unitPrice,
                        'total'           => round($unitPrice * $item->quantity, 3),
                    ]);

                    // Decrement stock
                    if ($variant) {
                        ProductVariant::where('id', $variantId)
                            ->decrement('stock', $item->quantity);
                    } else {
                        $item->product->decrement('stock', $item->quantity);
                    }
                }
            }

            // Remove pack from cart
            $cartEntry->delete();

            DB::commit();

            return response()->json([
                'success'       => true,
                'message'       => 'Pack order placed successfully!',
                'order_number'  => $order->order_number,
                'order_id'      => $order->id,
                'total'         => $total,
                'needs_payment' => $paymentMethod === 'card',
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[PackCart::checkout] ' . $e->getMessage(), [
                'file' => $e->getFile(), 'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to place order. Please try again.',
            ], 500);
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function formatEntry(PackCart $entry): array
    {
        $pack      = $entry->pack;
        $selection = collect($entry->selected_variants)->keyBy('pack_item_id');

        $items = $pack->items->map(function ($item) use ($selection) {
            $sel       = $selection->get($item->id);
            $variantId = $sel['variant_id'] ?? null;
            $variant   = $variantId
                ? $item->product->variants->firstWhere('id', $variantId)
                : null;
            $unitPrice = $variant
                ? (float) ($variant->price_override ?? $item->product->price)
                : (float) $item->product->price;
            $imgUrl = $item->product->primary_image_url;

            return [
                'pack_item_id' => $item->id,
                'product_id'   => $item->product_id,
                'product_name' => $item->product->name,
                'image_url'    => $imgUrl,
                'quantity'     => $item->quantity,
                'variant_id'   => $variantId,
                'variant_label'=> $variant?->label,
                'unit_price'   => $unitPrice,
                'line_total'   => round($unitPrice * $item->quantity, 3),
            ];
        })->values();

        return [
            'id'         => $entry->id,
            'pack_id'    => $pack->id,
            'pack_name'  => $pack->name,
            'pack_price' => (float) $pack->pack_price,
            'image_url'  => $pack->image_url,
            'items'      => $items,
        ];
    }
}
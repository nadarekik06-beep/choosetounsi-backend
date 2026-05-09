<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\SellerOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\Order;

/**
 * SellerOrderController
 *
 * ── KEY ARCHITECTURAL CHANGE ──
 * All operations now target the `seller_orders` table instead of `orders`.
 *
 * Previously: sellers queried `orders` using a list of order_ids derived from
 * their products — which meant they shared and could overwrite the same
 * `orders.status` field that other sellers' items also depended on.
 *
 * Now: each seller has their own `seller_orders` rows. Status and payment
 * updates are scoped exclusively to the authenticated seller's sub-orders.
 * Other sellers' sub-orders are completely unaffected.
 *
 * ── VARIANT FIX ──
 * show() now includes full variant details (label, attributes, image) for
 * each order item so the seller knows exactly what was ordered.
 *
 * ── PRIVACY FIX ──
 * Customer email is removed from all seller-facing API responses.
 * Sellers have no legitimate need for email — only name + wilaya for shipping.
 */
class SellerOrderController extends Controller
{
    /**
     * Base query: only this seller's sub-orders.
     *
     * CHANGE: added 'items.variant.images' to the eager load so the
     * resolved_image_url accessor on OrderItem can use the variant's own
     * images (each variant stores its own images per your architecture).
     */
    private function sellerOrderQuery(int $sellerId)
    {
        return SellerOrder::where('seller_id', $sellerId)
            ->with([
                'order.user:id,name',          // ← email removed from select
                'items.product.images',        // ← product-level fallback images
                'items.variant.attributeOptions.attribute',
                'items.variant.images',        // ← variant_id-linked images
            ]);
    }

    /* ── GET /api/seller/orders/stats ── */
    public function stats(Request $request)
    {
        $sellerId = auth()->id();
        $base     = SellerOrder::where('seller_id', $sellerId);

        return response()->json(['success' => true, 'data' => [
            'total'     => (clone $base)->count(),
            'pending'   => (clone $base)->where('status', 'pending')->count(),
            'completed' => (clone $base)->where('status', 'completed')->count(),
            'delivered' => (clone $base)->where('status', 'delivered')->count(),
            'cancelled' => (clone $base)->where('status', 'cancelled')->count(),
            'revenue'   => (clone $base)
                ->whereIn('status', ['completed', 'delivered'])
                ->sum('subtotal'),
        ]]);
    }

    /* ── GET /api/seller/orders ── */
    public function index(Request $request)
    {
        $sellerId = auth()->id();
        $query    = $this->sellerOrderQuery($sellerId);

        // ── Filters ────────────────────────────────────────────────────────
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }
        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('order', function ($q) use ($search) {
                // Internal server-side search by email is fine — email is
                // never returned in the response, only used for matching.
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhereHas('user', fn($u) =>
                      $u->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                  );
            });
        }

        $sellerOrders = $query->latest()->paginate((int) $request->query('per_page', 12));

        $sellerOrders->getCollection()->transform(fn($so) => $this->formatSellerOrder($so));

        return response()->json(['success' => true, 'data' => $sellerOrders]);
    }

    /* ── GET /api/seller/orders/{id} ── */
    public function show(Request $request, $id)
    {
        $sellerId    = auth()->id();
        $sellerOrder = $this->sellerOrderQuery($sellerId)->findOrFail($id);

        // ── Map items with full variant details ───────────────────────────
        $mappedItems = $sellerOrder->items->map(function ($item) {

            $productName = $item->product_name
                ?? $item->product?->name
                ?? "Product #{$item->product_id}";

            // ── Variant attributes ────────────────────────────────────────
            // Build a clean array of { label, value } pairs from the loaded
            // variant relationship. Example output:
            //   [{ label: 'Color', value: 'Red' }, { label: 'Size', value: 'M' }]
            $variantAttributes = [];
            $variantLabel      = $item->variant_label ?? null;

            if ($item->variant && $item->variant->relationLoaded('attributeOptions')) {
                foreach ($item->variant->attributeOptions as $opt) {
                    $attr = $opt->attribute;
                    if (!$attr) continue;

                    $variantAttributes[] = [
                        'slug'      => $attr->slug,
                        'label'     => $attr->name,         // human name, e.g. "Color"
                        'value'     => $opt->value,         // e.g. "Red"
                        'color_hex' => $opt->color_hex ?? null,
                    ];
                }

                // If no snapshot label was stored at checkout time, build one
                // from live data so the seller always sees something meaningful.
                if (!$variantLabel && !empty($variantAttributes)) {
                    $variantLabel = collect($variantAttributes)
                        ->pluck('value')
                        ->filter()
                        ->join(' / ');
                }
            }

            // ── Variant image ─────────────────────────────────────────────
            // Priority:
            //   1. Variant's own images linked by variant_id
            //   2. Color-group images linked by color_option_id
            //      (multi-color variants store images this way — e.g. Black+Red+Pink)
            //   3. Stored checkout snapshot (image_url column)
            //   4. Product primary image (last resort)
            //   5. null — frontend shows a placeholder
            $resolvedImage = null;

            // 1. Direct variant_id images
            if ($item->variant && $item->variant->relationLoaded('images')
                && $item->variant->images->isNotEmpty()) {
                $resolvedImage = Storage::url(
                    $item->variant->images->first()->image_path
                );
            }

            // 2. Color-option images — look up via the primary color option ID
            //    for this variant (the lowest color option ID in the group).
            if (!$resolvedImage && $item->variant) {
                $colorOptionId = $item->variant->color_option_id; // accessor on ProductVariant
                if ($colorOptionId) {
                    $colorImage = \App\Models\ProductImage::where('product_id', $item->product_id)
                        ->where('color_option_id', $colorOptionId)
                        ->orderBy('order')
                        ->first();
                    if ($colorImage) {
                        $resolvedImage = Storage::url($colorImage->image_path);
                    }
                }
            }

            // 3. Stored checkout snapshot
            if (!$resolvedImage && !empty($item->image_url)) {
                $stored = $item->image_url;
                $resolvedImage = str_starts_with($stored, 'http')
                    ? $stored
                    : url($stored);
            }

            // 4. Product primary image
            if (!$resolvedImage && $item->product) {
                $product = $item->product;
                if ($product->relationLoaded('images')) {
                    $primary = $product->images->firstWhere('is_primary', true)
                             ?? $product->images->sortBy('order')->first();
                    if ($primary) {
                        $resolvedImage = Storage::url($primary->image_path);
                    }
                }
            }

            return [
                'id'                 => $item->id,
                'product_id'         => $item->product_id,
                'product_name'       => $productName,
                'quantity'           => (int) $item->quantity,
                'unit_price'         => (float) $item->unit_price,
                'total'              => (float) $item->total,

                // ── NEW: variant fields ────────────────────────────────────
                'variant_id'         => $item->variant_id,
                'variant_label'      => $variantLabel,
                'variant_attributes' => $variantAttributes,  // [{ slug, label, value, color_hex }]
                'variant_image_url'  => $resolvedImage,
            ];
        });

        $order = $sellerOrder->order;

        // ── PRIVACY FIX: email is intentionally excluded ───────────────────
        // Sellers only need the customer's name for order preparation and
        // the wilaya for shipping. Email is private customer data.
        $customer = $order->user ? [
            'name' => $order->user->name,
            // 'email' => INTENTIONALLY OMITTED — privacy policy
        ] : null;

        return response()->json([
            'success' => true,
            'data'    => [
                'order' => array_merge($order->toArray(), [
                    'status'          => $sellerOrder->status,
                    'payment_status'  => $sellerOrder->payment_status,
                    'payment_method'  => $order->payment_method,
                    'wilaya'          => $order->wilaya ?? $order->shipping_address ?? null,
                    'customer'        => $customer,
                    'seller_order_id' => $sellerOrder->id,
                ]),
                'items'           => $mappedItems->values(),
                'seller_subtotal' => round((float) $sellerOrder->subtotal, 3),
            ],
        ]);
    }

    /* ── PATCH /api/seller/orders/{id}/status ── */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:pending,processing,completed,delivered,cancelled',
        ]);

        $sellerId    = auth()->id();
        $sellerOrder = SellerOrder::where('seller_id', $sellerId)->findOrFail($id);
        $sellerOrder->update(['status' => $request->status]);

        $this->syncParentOrderStatus($sellerOrder->order_id);

        return response()->json([
            'success' => true,
            'message' => 'Order status updated.',
            'data'    => $sellerOrder,
        ]);
    }

    /**
     * Derive and write the correct aggregate status to orders.status
     * based on the current state of all seller_orders for that order.
     */
    private function syncParentOrderStatus(int $orderId): void
    {
        $statuses = SellerOrder::where('order_id', $orderId)
            ->pluck('status')
            ->toArray();

        if (empty($statuses)) return;

        $unique = array_unique($statuses);

        $derived = match(true) {
            $unique === ['cancelled']
                => 'cancelled',
            $unique === ['delivered']
                => 'delivered',
            count(array_diff($unique, ['completed', 'delivered'])) === 0
                => 'completed',
            default => 'processing',
        };

        Order::where('id', $orderId)->update(['status' => $derived]);
    }

    /* ── PATCH /api/seller/orders/{id}/payment ── */
    public function updatePayment(Request $request, $id)
    {
        $request->validate([
            'payment_status' => 'required|in:unpaid,paid,refunded',
        ]);

        $sellerId    = auth()->id();
        $sellerOrder = SellerOrder::where('seller_id', $sellerId)->findOrFail($id);
        $sellerOrder->update(['payment_status' => $request->payment_status]);

        return response()->json([
            'success' => true,
            'message' => 'Payment status updated.',
            'data'    => $sellerOrder,
        ]);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Transform a SellerOrder into the flat shape the seller orders TABLE
     * (list view) expects.
     *
     * PRIVACY FIX: email intentionally omitted.
     * The list view shows customer name + wilaya — enough for the seller.
     */
    private function formatSellerOrder(SellerOrder $so): array
    {
        $order = $so->order;
        return [
            'id'             => $so->id,
            'order_number'   => $order?->order_number,
            'status'         => $so->status,
            'payment_status' => $so->payment_status,
            'payment_method' => $order?->payment_method,
            'total_amount'   => (float) $so->subtotal,
            'wilaya'         => $order?->wilaya ?? $order?->shipping_address ?? null,
            'created_at'     => $so->created_at,
            'updated_at'     => $so->updated_at,
            'user_id'        => $order?->user_id,
            'user'           => $order?->user ? [
                'id'   => $order->user->id,
                'name' => $order->user->name,
                // 'email' => INTENTIONALLY OMITTED — privacy policy
            ] : null,
            'parent_order_id' => $order?->id,
        ];
    }
}

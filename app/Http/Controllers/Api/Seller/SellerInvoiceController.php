<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\SellerOrder;
use App\Models\SellerApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * SellerInvoiceController
 *
 * Dedicated endpoint for invoice data. Kept separate from SellerOrderController
 * so it can evolve independently (e.g. admin invoice access, bulk PDF later).
 *
 * GET /api/seller/orders/{id}/invoice
 *
 * Returns everything the invoice page needs in one response:
 *   - seller business info (from seller_applications)
 *   - order meta (number, date, wilaya, address, payment, shipping)
 *   - customer name only (no email — privacy)
 *   - items with full variant attributes + resolved image
 *   - totals (subtotal, shipping_fee, grand total)
 */
class SellerInvoiceController extends Controller
{
    public function show(Request $request, $sellerOrderId)
    {
        $sellerId = auth()->id();

        // ── Load the seller's sub-order (scoped to seller for security) ────
        $sellerOrder = SellerOrder::where('seller_id', $sellerId)
            ->with([
                'order.user:id,name',
                'items.product.images',
                'items.variant.attributeOptions.attribute',
                'items.variant.images',
            ])
            ->findOrFail($sellerOrderId);

        $order = $sellerOrder->order;

        // ── Seller business info ────────────────────────────────────────────
        $application = SellerApplication::where('user_id', $sellerId)
            ->where('status', 'approved')
            ->first();

        $sellerInfo = [
            'business_name' => $application?->business_name ?? auth()->user()->name,
            'full_name'     => $application?->full_name     ?? auth()->user()->name,
            'phone'         => $application?->phone_number  ?? null,
            'wilaya'        => $application?->wilaya        ?? null,
            'city'          => $application?->city          ?? null,
            'plan'          => $application?->plan          ?? 'free',
        ];

        // ── Items with full variant data ────────────────────────────────────
        $items = $sellerOrder->items->map(function ($item) {

            $productName = $item->product_name
                ?? $item->product?->name
                ?? "Product #{$item->product_id}";

            // Variant attributes — same logic as SellerOrderController::show()
            $variantAttributes = [];
            $variantLabel      = $item->variant_label ?? null;

            if ($item->variant && $item->variant->relationLoaded('attributeOptions')) {
                foreach ($item->variant->attributeOptions as $opt) {
                    $attr = $opt->attribute;
                    if (!$attr) continue;
                    $variantAttributes[] = [
                        'slug'      => $attr->slug,
                        'label'     => $attr->name,
                        'value'     => $opt->value,
                        'color_hex' => $opt->color_hex ?? null,
                    ];
                }
                if (!$variantLabel && !empty($variantAttributes)) {
                    $variantLabel = collect($variantAttributes)->pluck('value')->filter()->join(' / ');
                }
            }

            // Variant image — same 4-level priority chain
            $resolvedImage = null;

            // 1. Direct variant_id images
            if ($item->variant && $item->variant->relationLoaded('images')
                && $item->variant->images->isNotEmpty()) {
                $resolvedImage = Storage::url($item->variant->images->first()->image_path);
            }

            // 2. Color-option images (multi-color variants)
            if (!$resolvedImage && $item->variant) {
                $colorOptionId = $item->variant->color_option_id;
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

            // 3. Checkout snapshot
            if (!$resolvedImage && !empty($item->image_url)) {
                $stored = $item->image_url;
                $resolvedImage = str_starts_with($stored, 'http') ? $stored : url($stored);
            }

            // 4. Product primary image
            if (!$resolvedImage && $item->product) {
                $p = $item->product;
                if ($p->relationLoaded('images')) {
                    $primary = $p->images->firstWhere('is_primary', true)
                             ?? $p->images->sortBy('order')->first();
                    if ($primary) $resolvedImage = Storage::url($primary->image_path);
                }
            }

            return [
                'id'                 => $item->id,
                'product_name'       => $productName,
                'quantity'           => (int) $item->quantity,
                'unit_price'         => (float) $item->unit_price,
                'total'              => (float) $item->total,
                'variant_id'         => $item->variant_id,
                'variant_label'      => $variantLabel,
                'variant_attributes' => $variantAttributes,
                'variant_image_url'  => $resolvedImage,
            ];
        });

        // ── Totals ──────────────────────────────────────────────────────────
        $subtotal    = round((float) $sellerOrder->subtotal, 3);
        $shippingFee = round((float) ($order->shipping_fee ?? 8.000), 3);
        $grandTotal  = round($subtotal + $shippingFee, 3);

        return response()->json([
            'success' => true,
            'data'    => [
                'invoice_number'  => 'INV-' . $order->order_number,
                'order_number'    => $order->order_number,
                'order_date'      => $order->created_at->format('d/m/Y'),
                'order_date_iso'  => $order->created_at->toISOString(),
                'status'          => $sellerOrder->status,
                'payment_method'  => $order->payment_method,
                'payment_status'  => $sellerOrder->payment_status,

                // Delivery info
                'wilaya'          => $order->wilaya ?? null,
                'address'         => $order->address ?? null,
                'phone'           => $order->phone   ?? null,

                // Parties
                'seller'          => $sellerInfo,
                'customer'        => [
                    'name' => $order->user?->name ?? 'Client',
                    // email intentionally omitted
                ],

                // Line items
                'items'           => $items->values(),

                // Money
                'subtotal'        => $subtotal,
                'shipping_fee'    => $shippingFee,
                'grand_total'     => $grandTotal,
            ],
        ]);
    }
}
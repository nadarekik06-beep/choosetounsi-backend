<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\SellerOrder;
use App\Models\SellerApplication;
use Illuminate\Http\Request;

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
 *   - totals (subtotal, coupon discount, shipping_fee, grand total)
 *
 * Sellers may print this invoice and put it in the parcel, so it shows the
 * CLIENT's amounts only (owner's decision Q6): the prices the client saw
 * (delivery included), the multi-item discount, a free delivery and the total
 * collected. Never the seller's base prices. Orders placed before delivery was
 * included keep their own figures (their prices were the displayed ones).
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

            // ── As bought: the order line's purchase snapshot ─────────────
            // (image, attributes, label — later product edits don't change it)
            $snapshot          = $item->purchaseSnapshot();
            $variantAttributes = $snapshot['variant_attributes'];
            $variantLabel      = $snapshot['variant_label'];
            $resolvedImage     = $snapshot['image_url'];

            return [
                'id'                 => $item->id,
                'product_name'       => $productName,
                'quantity'           => (int) $item->quantity,
                // As displayed to the client (delivery included on current orders)
                'unit_price'         => $item->clientUnitPrice(),
                'total'              => $item->clientTotal(),
                'discount_amount'    => round((float) $item->discount_amount, 3),
                'net_total'          => round((float) ($item->net_total ?? $item->total) + $item->includedFeeTotal(), 3),
                'variant_id'         => $item->variant_id,
                'variant_label'      => $variantLabel,
                'variant_attributes' => $variantAttributes,
                'variant_image_url'  => $resolvedImage,
            ];
        });

        // ── Totals ──────────────────────────────────────────────────────────
        // Shipping is charged once per order and booked on the first seller_order
        // (see FinancialSnapshotService::deliveryFeeFor), so a multi-seller order
        // doesn't bill the customer's shipping on every seller's invoice.
        $money       = $sellerOrder->clientMoney();   // client amounts: displayed prices, multi-item discount, free delivery
        $subtotal    = $money['subtotal'];
        $discount    = $money['discount'];
        $shippingFee = $money['shipping'];
        $grandTotal  = $money['total'];
        // Cancelled: nothing is billed (no shipping either); original_total keeps the history
        $cancelled   = $sellerOrder->status === 'cancelled';

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
                    'name' => $order->buyerName() ?? 'Client',
                    // email intentionally omitted
                ],

                // Line items
                'items'           => $items->values(),

                // Money
                'subtotal'        => $subtotal,
                'discount_amount' => $discount,
                'coupon_code'     => $sellerOrder->coupon_code,
                'shipping_fee'    => $cancelled ? 0.0 : $shippingFee,
                'free_delivery'   => $money['free_delivery'],
                'multi_item_discount' => $cancelled ? 0.0 : $money['multi_item_discount'],
                'client_amounts'  => $sellerOrder->isIncludedPricing(),
                'grand_total'     => $cancelled ? 0.0 : $grandTotal,
                'original_total'  => $grandTotal,
                'is_cancelled'    => $cancelled,
            ],
        ]);
    }
}
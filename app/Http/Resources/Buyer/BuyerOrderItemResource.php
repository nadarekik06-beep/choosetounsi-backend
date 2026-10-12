<?php

namespace App\Http\Resources\Buyer;

use App\Models\OrderItem;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One order line as the buyer sees it: what they bought (as bought: name,
 * image, variant), what they paid and what came back. Allow-list only: never
 * the commission, the seller's share, stock or promotion internals.
 *
 * @mixin OrderItem
 */
class BuyerOrderItemResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var OrderItem $item */
        $item = $this->resource;
        $so   = $item->relationLoaded('sellerOrder') ? $item->sellerOrder : null;

        return [
            'id'                   => $item->id,
            'product_id'           => $item->product_id,
            'variant_id'           => $item->variant_id,
            'product_name'         => $item->product_name ?? $item->product?->getRawOriginal('name'),
            'variant_label'        => $item->displayVariantLabel(),
            'resolved_image_url'   => $item->displayImageUrl(),
            'quantity'             => (int) $item->quantity,             // kept (after refunded returns)
            'ordered_quantity'     => (int) $item->ordered_quantity,     // as bought
            'returned_quantity'    => (int) ($item->getAttribute('returned_quantity') ?? 0),
            'return_state'         => $item->return_state,               // returned | partially_returned | null
            'is_returned'          => $item->return_state === 'returned',
            'unit_price'           => round((float) $item->unit_price, 3),
            'total'                => round((float) $item->total, 3),
            'discount_amount'      => round((float) ($item->discount_amount ?? 0), 3),   // the buyer's coupon share
            'seller_order_id'      => $item->seller_order_id,
            'seller_order_status'  => $so?->status,
            'seller_order_payment' => $so?->payment_status,
            'product'              => $item->product ? [
                'slug'              => $item->product->slug,
                'primary_image_url' => $item->product->primary_image_url,
            ] : null,
        ];
    }
}

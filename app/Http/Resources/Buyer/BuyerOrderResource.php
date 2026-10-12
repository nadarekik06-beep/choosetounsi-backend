<?php

namespace App\Http\Resources\Buyer;

use App\Models\Order;
use App\Models\SellerOrder;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order as the buyer sees it (GET /api/client/orders, /api/client/orders/{id}).
 *
 * Explicit allow-list: their own shipping address, what they pay (items,
 * coupons, delivery fees per shop, totals), statuses, each shop's parcel
 * (public shop name, delivery step, courier reference) and their returns.
 * Never: commissions, seller payouts, the courier's cost / return fee, platform
 * margins, seller contributions, settlement data, seller contact or pickup
 * details, Intigo internals, admin notes, payment-provider ids.
 *
 * Expects sellerOrders(.seller, .deliveryAssignment.deliveryGuy), items(.product,
 * .variant) and complaints loaded.
 *
 * @mixin Order
 */
class BuyerOrderResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var Order $order */
        $order        = $this->resource;
        $sellerOrders = $order->sellerOrders->keyBy('id');

        // Lines carry their parcel's status (the storefront groups and labels them)
        $items = $order->items->map(function ($item) use ($sellerOrders) {
            if ($item->seller_order_id && $sellerOrders->has($item->seller_order_id)) {
                $item->setRelation('sellerOrder', $sellerOrders[$item->seller_order_id]);
            }
            return $item;
        });

        // subtotal − discount + shipping, live from the active parcels; a
        // cancelled order owes 0 (original_amounts keep the checkout figures)
        $money = $order->moneySummary();

        return [
            'id'               => $order->id,
            'order_number'     => $order->order_number,
            'status'           => $order->status,
            'display_status'   => $order->display_status,
            'return_status'    => $order->return_status,
            'payment_status'   => $order->payment_status,
            'payment_method'   => $order->payment_method ?? 'cod',
            'subtotal'         => $money['subtotal'],
            'discount_amount'  => $money['discount_amount'],
            'coupon_codes'     => $money['coupon_codes'],
            'shipping_fee'     => $money['shipping_fee'],
            'total_amount'     => $money['total'],
            'amount_due'       => $money['total'],
            'is_cancelled'     => $money['is_cancelled'],
            'original_amounts' => $money['original'],
            // The buyer's own shipping address
            'recipient_name'   => $order->recipient_name,
            'phone'            => $order->phone,
            'phone_secondary'  => $order->phone_secondary,
            'wilaya'           => $order->wilaya,
            'delegation'       => $order->delegation,
            'address'          => $order->address,
            'postal_code'      => $order->postal_code,
            'notes'            => $order->notes,
            'created_at'       => optional($order->created_at)->toISOString(),
            'updated_at'       => optional($order->updated_at)->toISOString(),
            'items'            => BuyerOrderItemResource::collection($items)->resolve($request),
            'seller_groups'    => $order->sellerOrders->map(fn (SellerOrder $so) => $this->parcel($so, $items, $request))->values()->all(),
            'returns'          => $order->relationLoaded('complaints')
                ? $order->complaints->sortByDesc('created_at')->values()->map(fn ($c) => [
                    'id'               => $c->id,
                    'reference'        => $c->reference,
                    'status'           => $c->status,
                    'refund_amount'    => (float) $c->refund_amount,
                    'refund_method'    => $c->refund_method,
                    'refund_reference' => $c->status === 'refunded' ? $c->refund_reference : null,
                    'refunded_at'      => optional($c->refunded_at)->toISOString(),
                    'created_at'       => optional($c->created_at)->toISOString(),
                ])->all()
                : [],
        ];
    }

    /** One shop's parcel: what the buyer pays for it and where it is. */
    private function parcel(SellerOrder $so, $items, $request): array
    {
        $assignment = $so->relationLoaded('deliveryAssignment') ? $so->deliveryAssignment : null;
        $sent       = (bool) $so->intigo_nid && $so->intigo_sync_status !== 'cancelled';

        return [
            'seller_order_id'  => $so->id,
            'shop'             => $so->isPlatformParcel()
                ? ['id' => null, 'name' => "CHOOSE'Tounsi"]
                : ['id' => $so->seller_id, 'name' => $so->relationLoaded('seller') ? $so->seller?->name : null],
            'status'           => $so->status,
            'display_status'   => $so->display_status,
            'return_status'    => $so->return_status,
            'payment_status'   => $so->payment_status,
            'subtotal'         => round((float) $so->subtotal, 3),
            'coupon_code'      => $so->coupon_code,
            'discount_amount'  => round((float) ($so->discount_amount ?? 0), 3),
            // What the buyer pays for this parcel's delivery (0 when the shop offers it)
            'delivery_fee'     => round((float) ($so->getAttribute('delivery_fee') ?? 0), 3),
            'is_free_delivery' => (bool) $so->getAttribute('is_free_delivery'),
            'items'            => BuyerOrderItemResource::collection($items->where('seller_order_id', $so->id)->values())->resolve($request),
            'tracking'         => [
                'assigned_at'  => $assignment?->assigned_at?->toISOString(),
                'picked_up_at' => $assignment?->picked_up_at?->toISOString(),
                'delivered_at' => $assignment?->delivered_at?->toISOString(),
                'delivery_guy' => $assignment?->relationLoaded('deliveryGuy') ? $assignment->deliveryGuy?->name : null,
            ],
            'delivery'         => [
                'stage'        => self::stage($so, $sent),
                'tracking_ref' => $sent ? $so->intigo_nid : null,
                'updated_at'   => optional($so->intigo_status_at ?? $so->updated_at)->toISOString(),
            ],
        ];
    }

    /** preparing | awaiting_pickup | in_transit | out_for_delivery | delivered | returning | returned | cancelled | pending */
    public static function stage(SellerOrder $so, bool $sent): string
    {
        return match ($so->status) {
            'pending'                            => 'pending',
            'confirmed'                          => $sent ? 'awaiting_pickup' : 'preparing',
            'handed_to_courier'                  => 'in_transit',
            'out_for_delivery'                   => 'out_for_delivery',
            'delivered', 'completed', 'refunded' => 'delivered',
            'refused'                            => 'returning',
            'returned_to_seller'                 => 'returned',
            'cancelled'                          => 'cancelled',
            default                              => (string) $so->status,
        };
    }
}

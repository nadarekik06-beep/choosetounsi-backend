<?php

namespace App\Http\Resources\Buyer;

use App\Models\Complaint;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A return / complaint as the buyer sees it (GET/POST/PATCH /api/client/complaints…).
 *
 * Allow-list: what they asked, its status and public timeline, the shop's
 * answer (seller_note, rejection reason), the refund they get, and the lines
 * as bought. Never: admin notes, reception / pickup notes, who handled it,
 * finance / settlement data, the seller's share of each line, refund task ids.
 *
 * timeline / public_events / cash_refund come from ReturnPresenter::forClient()
 * and complained_items from Complaint::withItemSnapshots() when the caller set them.
 *
 * @mixin Complaint
 */
class BuyerComplaintResource extends JsonResource
{
    /** Fields of each complained line the buyer may see. */
    private const ITEM_FIELDS = [
        'id', 'order_id', 'seller_order_id', 'product_id', 'variant_id', 'product_name', 'variant_label',
        'variant_attributes', 'quantity', 'returned_quantity', 'return_state', 'unit_price', 'total', 'image_url',
        'return_quantity', 'return_unit_price', 'return_amount', 'condition', 'restocked_quantity',
        'replacement_variant_id', 'replacement_label',
    ];

    public function toArray($request): array
    {
        /** @var Complaint $c */
        $c     = $this->resource;
        $order = $c->relationLoaded('order') ? $c->order : null;
        $date  = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->toISOString() : null;

        return [
            'id'                  => $c->id,
            'reference'           => $c->reference,
            'order_id'            => $c->order_id,
            'order'               => $order ? [
                'id'           => $order->id,
                'order_number' => $order->order_number,
                'status'       => $order->status,
                'created_at'   => optional($order->created_at)->toISOString(),
            ] : null,
            'complaint_type'      => $c->complaint_type,
            'resolution_type'     => $c->resolution_type,
            'return_scope'        => $c->return_scope,
            'other_reason'        => $c->other_reason,
            'description'         => $c->description,
            'image_url'           => $c->image_url,
            'image_urls'          => $c->image_urls,
            'status'              => $c->status,
            'can_escalate'        => (bool) $c->can_escalate,
            'cash_refund'         => $c->getAttribute('cash_refund'),
            // The shop's answer and the platform's decision, as shown to the buyer
            'seller_decision'     => $c->seller_decision,
            'seller_note'         => $c->seller_note,
            'rejection_reason'    => $c->rejection_reason,
            // Money the buyer gets back
            'shipping_payer'      => $c->shipping_payer,
            'return_shipping_fee' => round((float) $c->return_shipping_fee, 3),
            'items_amount'        => round((float) $c->items_amount, 3),
            'refund_amount'       => round((float) $c->refund_amount, 3),
            'refund_method'       => $c->refund_method,
            'refund_reference'    => $c->status === Complaint::STATUS_REFUNDED ? $c->refund_reference : null,
            'refund_status'       => $c->refund_status,
            'is_legacy_exchange'  => $c->isLegacyExchange(),
            // Exchange: cash to hand the courier with the replacement (0 when the shop is at fault)
            'exchange_fee'        => round((float) $c->exchange_fee, 3),
            'client_fee'          => round($c->clientFee(), 3),
            'fee_size'            => $c->fee_size,
            'exchange_tracking'   => $c->isExchange() ? $c->intigo_exchange_nid : null,
            'exchange_carrier_status' => $c->isExchange() ? $c->intigo_exchange_status_label : null,
            'exchange_shipped_at' => $date($c->exchange_shipped_at),
            'exchanged_at'        => $date($c->exchanged_at),
            'exchange_failed_at'  => $date($c->exchange_failed_at),
            'old_item_received_at' => $date($c->old_item_received_at),
            // Dates
            'seller_decided_at'   => $date($c->seller_decided_at),
            'escalated_at'        => $date($c->escalated_at),
            'pickup_scheduled_at' => $date($c->pickup_scheduled_at),
            'picked_up_at'        => $date($c->picked_up_at),
            'received_at'         => $date($c->received_at),
            'refunded_at'         => $date($c->refunded_at),
            'resolved_at'         => $date($c->resolved_at),
            'created_at'          => $date($c->created_at),
            'updated_at'          => $date($c->updated_at),
            'complained_items'    => $this->items((array) ($c->getAttribute('complained_items') ?? [])),
            'timeline'            => $c->getAttribute('timeline'),
            'public_events'       => $c->getAttribute('public_events'),
        ];
    }

    /**
     * Each returned line as the buyer saw it. Orders with delivery included in
     * the prices: the displayed unit price, never the seller's base price; the
     * amount given back is the return's items_amount / refund_amount above.
     */
    private function items(array $lines): array
    {
        $ids  = array_filter(array_map(fn ($l) => ((array) $l)['id'] ?? null, $lines));
        $rows = $ids
            ? \App\Models\OrderItem::whereIn('id', $ids)->whereNotNull('display_unit_price')->get()->keyBy('id')
            : collect();

        return array_map(function ($line) use ($rows) {
            $line = array_intersect_key((array) $line, array_flip(self::ITEM_FIELDS));
            if ($row = $rows->get($line['id'] ?? 0)) {
                $unit = $row->clientUnitPrice();
                $line['unit_price']        = $unit;
                $line['total']             = round($unit * (int) ($line['quantity'] ?? 0), 3);
                $line['return_unit_price'] = $unit;
                $line['return_amount']     = null;
            }
            return $line;
        }, $lines);
    }
}

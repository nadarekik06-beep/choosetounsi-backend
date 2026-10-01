<?php

namespace App\Http\Resources\Admin;

use App\Models\Order;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The buyer's shipping address snapshot of an order, for the admin only.
 *
 * status: complete — structured address captured at checkout
 *         legacy   — pre-2026-10 order: free-text address, no recipient /
 *                    delegation / postal code
 *         missing  — nothing a courier could use
 *
 * @property Order $resource
 */
class ShippingAddressResource extends JsonResource
{
    public function toArray($request): array
    {
        $order = $this->resource;

        return [
            'status'          => $order->hasStructuredAddress() ? 'complete' : ($order->hasDeliverableAddress() ? 'legacy' : 'missing'),
            'recipient_name'  => $order->recipientName(),
            'phone'           => $order->phone,
            'phone_secondary' => $order->phone_secondary,
            'wilaya'          => $order->wilaya,
            'delegation'      => $order->delegation,
            'address'         => $order->address,
            'postal_code'     => $order->postal_code,
            'notes'           => $order->notes,
            'formatted'       => $order->formattedShippingAddress(),
        ];
    }
}

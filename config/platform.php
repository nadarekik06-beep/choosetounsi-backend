<?php
return [
    'platform_user_id' => 1,

    // What the delivery agency bills per shipment (one shipment per order).
    // The platform always pays this; it is recovered from the customer
    // (shipping_fee) or, for free-shipping orders, from the seller's earnings.
    'shipping_cost' => (float) env('SHIPPING_AGENCY_COST', 8.0),

    // What the agency bills for a return pick-up (client → seller). Paid by
    // the seller when the item was wrong / defective, else deducted from the
    // client's refund (App\Services\Returns\ReturnService).
    'return_shipping_fee' => (float) env('RETURN_SHIPPING_FEE', env('SHIPPING_AGENCY_COST', 8.0)),
];

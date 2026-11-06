<?php

// Seller sub-order notifications: e-mail + dashboard bell.
// App\Notifications\Orders\*  ·  resources/views/emails/seller-orders/*

return [
    'money' => ':amount DT',

    'common' => [
        'greeting'      => 'Hello :name,',
        'greeting_anon' => 'Hello,',
        'reference'     => 'Order reference',
        'order_date'    => 'Ordered on',
        'buyer'         => 'Customer',
        'items'         => 'Items',
        'qty'           => 'Qty',
        'items_total'   => 'Items total',
        'discount'      => 'Your coupon',
        'platform_funded' => "Discount paid by Choose'Tounsi",
        'commission'    => "Choose'Tounsi commission",
        'shipping'      => 'Shipping you cover',
        'net'           => 'Your net earnings',
        'view_order'    => 'View the order',
        'reason'        => "You receive this e-mail because you sell on Choose'Tounsi and it concerns an order in your shop.",
        'help'          => 'Questions? Contact us at :email.',
    ],

    'pickup' => [
        'heading'    => 'Pickup address',
        'incomplete' => "Your pickup address is incomplete (missing: :fields). The courier can't collect the package until it is complete.",
        'fix'        => 'Complete my pickup address',
    ],

    'pickup_fields' => [
        'shop_name'   => 'shop name',
        'contact'     => 'contact person',
        'phone'       => 'valid phone',
        'address'     => 'street address',
        'city'        => 'city / delegation',
        'postal_code' => 'postal code',
        'wilaya'      => 'governorate',
        'zone'        => 'zone (delegation / sector)',
        'gps'         => 'map position',
    ],

    'tips' => [
        'heading' => 'Packing tips',
        'list'    => [
            'Pack every item in a sealed, sturdy package (tape all openings).',
            'Write the order reference :ref clearly on the package.',
            'Protect fragile items with bubble wrap or paper.',
            'Have the package ready at your pickup address — the courier will call before coming.',
        ],
    ],

    'placed' => [
        'subject'   => 'New order :ref — :count item|New order :ref — :count items',
        'title'     => 'New order :ref',
        'body'      => ':count item · awaiting confirmation|:count items · awaiting confirmation',
        'headline'  => 'You have a new order',
        'intro'     => "Awaiting confirmation by Choose'Tounsi — don't ship anything yet.",
    ],

    'confirmed' => [
        'subject'   => 'Order :ref confirmed — prepare the package|Order :ref confirmed — prepare the package',
        'title'     => 'Order :ref confirmed — prepare it',
        'body'      => ':count item to pack · a courier will pick it up|:count items to pack · a courier will pick them up',
        'headline'  => 'Prepare the package',
        'intro'     => 'The order is confirmed. A courier will pick it up at your pickup address.',
        'ref_hint'  => 'Write this reference on the package',
        'next'      => 'When the package is ready, set the order to “Completed” in your dashboard.',
    ],

    'cancelled' => [
        'subject'   => 'Order :ref cancelled',
        'title'     => 'Order :ref cancelled',
        'body'      => 'Do not prepare or ship it.|Do not prepare or ship it.',
        'headline'  => 'Order cancelled',
        'intro'     => 'This order has been cancelled. Please do not prepare or ship it.',
        'items'     => 'Cancelled products',
    ],

    'pickup_reminder' => [
        'subject'   => 'Reminder: order :ref is waiting to be prepared|Reminder: order :ref is waiting to be prepared',
        'title'     => 'Order :ref is waiting to be prepared',
        'body'      => 'Confirmed but not ready for pickup yet.|Confirmed but not ready for pickup yet.',
        'headline'  => 'Your order is waiting',
        'intro'     => 'This order was confirmed but is not marked ready for pickup yet. Please prepare the package so the customer gets it quickly.',
        'ref_hint'  => 'Write this reference on the package',
        'next'      => 'When the package is ready, set the order to “Completed” in your dashboard.',
    ],
    // Delivery agency (Intigo) updates: dashboard bell
    'bordereau_ready' => [
        'subject'  => "Order :ref — your bordereau is ready to print|Order :ref — your bordereau is ready to print",
        'title'    => "Bordereau ready — :ref",
        'body'     => "Print the delivery bordereau from the order page and stick it on the parcel.|Print the delivery bordereau from the order page and stick it on the parcel.",
        'headline' => "Your bordereau is ready",
        'intro'    => "The delivery bordereau of this order is in your dashboard. Print it and stick it on the parcel before the courier comes.",
        'next'     => "Open the order and click “Print bordereau”.",
        'cta'      => "Open the order",
    ],

    'bordereau_updated' => [
        'subject'  => "Order :ref — updated bordereau, print the new one|Order :ref — updated bordereau, print the new one",
        'title'    => "Bordereau updated — :ref",
        'body'     => "A new bordereau replaces the previous one: print it from the order page and stick it on the parcel.|A new bordereau replaces the previous one: print it from the order page and stick it on the parcel.",
        'headline' => "Your bordereau was updated",
        'intro'    => "Choose'Tounsi replaced the bordereau of this order. Print the new one and use it instead of the previous one.",
        'next'     => "Open the order and click “Print bordereau”. Do not use the old one.",
        'cta'      => "Open the order",
    ],

    'parcel_picked_up' => [
        'title' => 'Parcel :ref picked up',
        'body'  => 'The courier picked up your parcel (:count item): it is on its way to the customer.|The courier picked up your parcel (:count items): it is on its way to the customer.',
    ],
    'parcel_out_for_delivery' => [
        'title' => 'Parcel :ref out for delivery',
        'body'  => 'The courier is delivering your parcel (:count item) to the customer today.|The courier is delivering your parcel (:count items) to the customer today.',
    ],
    'parcel_delivered' => [
        'title' => 'Parcel :ref delivered',
        'body'  => 'Delivered and paid by the customer (:count item). Your earnings are on their way.|Delivered and paid by the customer (:count items). Your earnings are on their way.',
    ],
    'parcel_returned' => [
        'title' => 'Parcel :ref returned to you',
        'body'  => 'The customer did not take the parcel (:count item): it is back at your pickup address and its stock is restored.|The customer did not take the parcel (:count items): it is back at your pickup address and its stock is restored.',
    ],
];

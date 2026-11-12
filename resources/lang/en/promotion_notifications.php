<?php

// Seller notifications about promotions created by Choose'Tounsi (App\Notifications\Sellers\AdminPromotionNotification)
return [
    'greeting'  => 'Hello :name,',
    // Sent once to the sellers whose products offered free delivery (pricing:notify-free-delivery-sellers)
    'free_delivery_everywhere' => [
        'title' => 'Delivery is now free on the whole platform',
        'body' => 'Delivery is now free on the whole platform and included in the price shown to the customer. You keep receiving your price minus the commission.',
        'detail' => 'The "free delivery" option of your products no longer exists: nothing is deducted from your earnings for delivery any more. Just check that the parcel size of each product is right.',
        'cta' => 'See my products',
    ],
    'details'   => 'Discount: :discount · from :starts to :ends.',
    'funding'   => 'As with your own promotions, your earnings and the commission are calculated on the discounted price.',
    'read_only' => "Promotions created by Choose'Tounsi appear in your dashboard (Promotions) as read-only.",
    'cta'       => 'View my promotions',

    'created' => [
        'title' => "New Choose'Tounsi promotion: :name",
        'body'  => "Choose'Tounsi started the promotion \":name\" (:discount) on: :products.",
    ],
    'updated' => [
        'title' => "Choose'Tounsi promotion changed: :name",
        'body'  => "Choose'Tounsi changed the promotion \":name\" (:discount) on: :products.",
    ],
    'paused' => [
        'title' => "Choose'Tounsi promotion paused: :name",
        'body'  => "The promotion \":name\" is paused: :products are back to their normal price.",
    ],
    'resumed' => [
        'title' => "Choose'Tounsi promotion resumed: :name",
        'body'  => "The promotion \":name\" (:discount) is running again on: :products.",
    ],
    'ended' => [
        'title' => "Choose'Tounsi promotion ended: :name",
        'body'  => "Choose'Tounsi ended the promotion \":name\": :products are back to their normal price.",
    ],
    'replaced' => [
        'title' => "Your promotion \":name\" was replaced",
        'body'  => "Choose'Tounsi moved :products into its own promotion: they are no longer part of your promotion \":name\".",
    ],
];

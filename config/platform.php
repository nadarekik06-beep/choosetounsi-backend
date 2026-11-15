<?php
return [
    'platform_user_id' => 1,

    // Choose'Tounsi invoice for sellers (GET /api/seller/orders/{id}/invoice).
    // Off: sellers only print the Intigo bordereau the admin uploads.
    // Set SELLER_INVOICE_ENABLED=true in .env to bring it back.
    'seller_invoice_enabled' => (bool) env('SELLER_INVOICE_ENABLED', false),

    // Delivery amounts (client fee, agency cost, seller free-delivery
    // contribution, return shipping, refused parcels) are admin settings:
    // table delivery_settings, App\Services\Delivery\DeliverySettings.
];

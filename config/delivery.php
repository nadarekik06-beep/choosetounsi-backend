<?php

/*
|--------------------------------------------------------------------------
| Delivery documents
|--------------------------------------------------------------------------
|
| platform_pickup — where the courier collects CHOOSE'Tounsi's own (platform
| brand) products. Sellers' pickup addresses live on their seller profile;
| the platform has no seller application, so its address is configured here.
| Leave a value empty and the admin panel flags the platform sub-order as
| "pickup address incomplete", exactly like a seller's.
|
*/

return [
    'platform_pickup' => [
        'shop_name'   => env('PLATFORM_PICKUP_NAME', "CHOOSE'Tounsi"),
        'contact'     => env('PLATFORM_PICKUP_CONTACT'),
        'phone'       => env('PLATFORM_PICKUP_PHONE'),
        'address'     => env('PLATFORM_PICKUP_ADDRESS'),
        'city'        => env('PLATFORM_PICKUP_CITY'),
        'postal_code' => env('PLATFORM_PICKUP_POSTAL_CODE'),
        'wilaya'      => env('PLATFORM_PICKUP_WILAYA'),
        'notes'       => env('PLATFORM_PICKUP_NOTES'),
    ],

    // Printed on every slip: who to call about a parcel.
    'support_phone' => env('PLATFORM_SUPPORT_PHONE'),
];

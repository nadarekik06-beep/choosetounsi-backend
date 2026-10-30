<?php

/*
|--------------------------------------------------------------------------
| Guest checkout (order without an account)
|--------------------------------------------------------------------------
| Off → everything behaves as before: cart and checkout need a login.
|
| Instant switch in production (no deploy, no config:cache):
|   php artisan guest-checkout off | on | status | reset
| The command stores an override in platform_settings that wins over
| GUEST_CHECKOUT_ENABLED; "reset" removes it so the .env value applies again.
*/

return [
    'enabled' => (bool) env('GUEST_CHECKOUT_ENABLED', false),

    // A guest may have at most this many orders still 'pending' (not yet
    // confirmed by the team) on the same phone number.
    'max_pending_per_phone' => (int) env('GUEST_CHECKOUT_MAX_PENDING_PER_PHONE', 3),

    // Guest order placement (POST /checkout, /checkout/buy-now without a login)
    'orders_per_ip_per_hour'    => (int) env('GUEST_CHECKOUT_ORDERS_PER_IP_PER_HOUR', 30), // mobile users often share one IP
    'orders_per_phone_per_hour' => (int) env('GUEST_CHECKOUT_ORDERS_PER_PHONE_PER_HOUR', 5),

    // Public order tracking lookups (by order number + phone), per IP
    'lookups_per_ip_per_hour' => (int) env('GUEST_CHECKOUT_LOOKUPS_PER_IP_PER_HOUR', 20),

    // Guest carts nobody touched for this many days are deleted (daily job)
    'cart_ttl_days' => (int) env('GUEST_CART_TTL_DAYS', 30),
];

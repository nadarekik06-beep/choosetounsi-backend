<?php

/*
|--------------------------------------------------------------------------
| Intigo delivery API (V3)
|--------------------------------------------------------------------------
|
| mode     fake (default): App\Services\Intigo\FakeIntigoClient answers every
|          parcel call locally (nid "FAKE-{seller_order_id}"), logs it in
|          intigo_logs and never touches the network. live: real API calls,
|          real parcels, real drivers. Switch only once everything is set up.
| api_key  never commit it; it is redacted from every log.
| The webhook secret is in config/services.php (services.intigo.webhook_secret).
|
| The regions endpoints (/regions/*) are public and read-only: intigo:sync-regions
| reads them in both modes.
|
*/

return [
    'mode'           => env('INTIGO_MODE', 'fake') === 'live' ? 'live' : 'fake',
    'api_key'        => env('INTIGO_API_KEY'),
    'base_url'       => rtrim(env('INTIGO_BASE_URL', 'https://api.intigo.net/api/v3'), '/'),

    // Default of the admin "customer may open the parcel" switch (Delivery & Fees page)
    'can_open_default' => filter_var(env('INTIGO_CAN_OPEN_DEFAULT', false), FILTER_VALIDATE_BOOLEAN),

    // Pickup address index of CHOOSE'Tounsi's own products (sellers: set per seller in the admin)
    'platform_pickup_index' => env('INTIGO_PLATFORM_PICKUP_INDEX'),

    // Intigo's pickup addresses have no contact field: append "— Contact : name phone"
    // to street_address so the driver calls the seller, not the CHOOSE'Tounsi account.
    'pickup_contact_in_address' => filter_var(env('INTIGO_PICKUP_CONTACT_IN_ADDRESS', true), FILTER_VALIDATE_BOOLEAN),

    // Identification headers recommended by Intigo on parcel creation
    'platform'         => 'choosetounsi',
    'platform_version' => '1.0',
    'shop'             => 'choosetounsi.tn',

    'timezone' => 'Africa/Tunis',

    'http' => [
        'timeout'         => (int) env('INTIGO_TIMEOUT', 20),
        'connect_timeout' => (int) env('INTIGO_CONNECT_TIMEOUT', 8),
    ],

    // Parcel creation retries: only timeouts / 5xx / connection errors, never 4xx.
    'send' => [
        'max_attempts' => 4,
        'backoff'      => [60, 300, 900],   // seconds between attempts
        'lock_seconds' => 120,              // a send claimed longer ago than this is considered dead
    ],

    // Parcel lookup by our cid (before any re-send): GET /parcels/ has no cid filter, so the
    // newest parcels are read page by page and matched here (page_size max 100 at Intigo).
    'cid_lookup' => [
        'page_size' => 100,
        'max_pages' => 10,
    ],

    // E-mail for parcels that need a human (creation failed, possible duplicate); bell for all.
    // Empty = bell only. Defaults to the team inbox (MAIL_CONTACT_TO).
    'alert_email' => env('INTIGO_ALERT_EMAIL', env('MAIL_CONTACT_TO')),

    // Webhook events and logs kept for this many days (intigo:sync-statuses prunes)
    'retention_days' => 180,
];

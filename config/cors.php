<?php

return [

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'auth/*',
        'admin/*',        // ← Add this if routes are /admin/*
        'storage/*',      // ← Add this for image access
    ],

    'allowed_methods' => ['*'],

    // Comma-separated in .env, e.g.
    // CORS_ALLOWED_ORIGINS=https://choosetounsi.tn,https://www.choosetounsi.tn,https://admin.choosetounsi.tn
    // Unset or empty → the local dev servers (storefront :3000, admin :3001, :3002).
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',',
        env('CORS_ALLOWED_ORIGINS') ?: implode(',', [
            'http://localhost:3000',
            'http://localhost:3001',
            'http://localhost:3002',
            'http://127.0.0.1:3000',
            'http://127.0.0.1:3001',
            'http://127.0.0.1:3002',
        ])
    )))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // Lets the admin panel read the server's file name on PDF downloads.
    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 0,

    'supports_credentials' => true,

];
<?php

// =============================================================
// config/auth.php — ADD these entries to your existing file
// =============================================================
// In 'guards' array, add:
//
//  'admin' => [
//      'driver'   => 'sanctum',   // uses Sanctum but a separate guard
//      'provider' => 'admins',
//  ],
//
// In 'providers' array, add:
//
//  'admins' => [
//      'driver' => 'eloquent',
//      'model'  => App\Models\Admin::class,
//  ],
//
// =============================================================
// FULL REPLACEMENT config/auth.php:
// =============================================================

return [

    'defaults' => [
        'guard'     => 'web',
        'passwords' => 'users',
    ],

    'guards' => [
        'web' => [
            'driver'   => 'session',
            'provider' => 'users',
        ],

        'api' => [
            'driver'   => 'sanctum',
            'provider' => 'users',
        ],

        
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model'  => App\Models\User::class,
        ],

       
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table'    => 'password_resets',
            'expire'   => 60,
            'throttle' => 60,
        ],

        'admins' => [
            'provider' => 'admins',
            'table'    => 'password_resets',
            'expire'   => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,
];
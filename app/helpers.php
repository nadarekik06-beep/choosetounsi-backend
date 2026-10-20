<?php

use App\Support\AppLinks;

/*
 * Global helpers (composer.json autoload.files).
 * Page links in e-mails and notifications: frontend_url() / admin_url(),
 * never url() / route() / config('app.url') (= the API domain).
 */

if (!function_exists('frontend_url')) {
    /** Storefront page: frontend_url('/seller') → https://choosetounsi.tn/seller */
    function frontend_url(string $path = ''): string
    {
        return AppLinks::frontend($path);
    }
}

if (!function_exists('admin_url')) {
    /** Admin panel page: admin_url('/complaints?id=3') → https://admin.choosetounsi.tn/complaints?id=3 */
    function admin_url(string $path = ''): string
    {
        return AppLinks::admin($path);
    }
}

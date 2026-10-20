<?php

namespace App\Support;

/**
 * Links that leave the API: pages of the storefront (choosetounsi.tn, seller
 * space under /seller/*) and of the admin panel.
 *
 * E-mails and notifications must build page links with these (or the
 * frontend_url() / admin_url() helpers), never with url(), route(), asset()
 * or config('app.url'): those point at the API domain, which has no pages.
 * tests/Feature/EmailLinksTest renders every e-mail and fails otherwise.
 */
final class AppLinks
{
    /** Storefront URL: frontend('/seller') → https://choosetounsi.tn/seller; frontend() → the bare origin. */
    public static function frontend(string $path = ''): string
    {
        return self::join((string) config('app.frontend_url'), $path);
    }

    /** Admin panel URL: admin('/complaints?id=3') → https://admin.choosetounsi.tn/complaints?id=3. */
    public static function admin(string $path = ''): string
    {
        return self::join((string) config('app.admin_url'), $path);
    }

    private static function join(string $base, string $path): string
    {
        $base = rtrim($base, '/');
        if ($path === '' || $path === '/') {
            return $path === '/' ? $base . '/' : $base;
        }
        // "?utm=…" / "#x" → home page with that query / anchor
        return $base . ($path[0] === '/' ? $path : '/' . $path);
    }
}

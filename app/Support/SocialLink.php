<?php

namespace App\Support;

/**
 * Social and website links typed by sellers, turned into full URLs.
 *
 * Accepts what people actually type — "flamingofashionsfax", "@flamingofashionsfax",
 * "instagram.com/flamingofashionsfax", a full link — and returns
 * "https://instagram.com/flamingofashionsfax". Same rules as the storefront's
 * lib/sellerApplication/normalize.ts (keep both in sync).
 */
class SocialLink
{
    /** A full link: scheme + a host with a dot, no spaces. Checked after normalize(). */
    const PATTERN = '/^https?:\/\/[^\s\/?#]+\.[^\s\/?#]{2,}([\/?#]\S*)?$/i';

    const NETWORKS = [
        'instagram' => ['base' => 'https://instagram.com/', 'hosts' => ['instagram.com', 'instagr.am']],
        'facebook'  => ['base' => 'https://facebook.com/',  'hosts' => ['facebook.com', 'fb.com', 'fb.me']],
        'tiktok'    => ['base' => 'https://tiktok.com/@',   'hosts' => ['tiktok.com']],
    ];

    /** A username: letters, digits, dots, underscores, dashes (with or without "@"). */
    const HANDLE = '/^@?([A-Za-z0-9._-]{1,100})$/';

    /**
     * $network: instagram | facebook | tiktok. Empty → null. Something that is neither
     * a link nor a username is returned trimmed, and PATTERN refuses it.
     */
    public static function social(string $network, mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        $v = trim($value);
        if ($v === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $v)) {
            return $v;
        }
        // A link without "https://": "instagram.com/x", "www.facebook.com/x", "linktr.ee/x"
        if (str_contains($v, '/') || self::mentionsHost($network, $v)) {
            return preg_match('/\s/', $v) ? $v : 'https://' . ltrim($v, '/');
        }
        if (preg_match(self::HANDLE, $v, $m)) {
            return self::NETWORKS[$network]['base'] . $m[1];
        }
        return $v;
    }

    /** "votreboutique.tn" → "https://votreboutique.tn". Empty → null. */
    public static function website(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        $v = trim($value);
        if ($v === '') {
            return null;
        }
        return preg_match('#^https?://#i', $v) || preg_match('/\s/', $v) ? $v : 'https://' . ltrim($v, '/');
    }

    private static function mentionsHost(string $network, string $value): bool
    {
        foreach (self::NETWORKS[$network]['hosts'] as $host) {
            if (stripos($value, $host) !== false) {
                return true;
            }
        }
        return false;
    }
}

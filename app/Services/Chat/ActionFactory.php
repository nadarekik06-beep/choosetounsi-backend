<?php

namespace App\Services\Chat;

use Illuminate\Support\Facades\Log;

/**
 * Builds the buttons returned with chatbot replies.
 *
 *   link        → navigates to an INTERNAL page. URLs are checked against an
 *                 allowlist of real storefront routes; anything else (other
 *                 hosts, "//", schemes, unknown paths) is dropped and logged.
 *   quick_reply → sends `message` back to the bot as if the user typed it.
 *
 * "#cart" is the one non-path link: the storefront has no /cart page, the
 * widget opens the cart drawer instead.
 */
class ActionFactory
{
    /** Exact internal paths that exist in the Next.js storefront. */
    private const EXACT = [
        '/', '/shop', '/deals', '/discover', '/search', '/favorites', '/checkout', '/orders',
        '/complaints', '/complaints/new', '/become-a-vendor', '/profile', '/account/addresses',
        '/auth/login', '/auth/register', '/auth/forgot-password',
        '/seller', '/seller/products', '/seller/subscription', '/seller/orders',
        '#cart',
    ];

    /** Dynamic storefront routes: /category/[slug], /products/[slug], /deals/[slug], /sellers/[id]. */
    private const PATTERNS = [
        '#^/category/[a-z0-9-]+$#',
        '#^/products/[a-z0-9-]+$#',
        '#^/deals/[a-z0-9-]+$#',
        '#^/sellers/[0-9]+$#',
    ];

    /** Query strings allowed on specific paths. */
    private const QUERY_RULES = [
        '/auth/login'    => '#^redirect=/[a-z0-9/-]*$#',
        '/auth/register' => '#^redirect=/[a-z0-9/-]*$#',
        '/search'        => '#^q=[\p{L}\p{N}%+ -]{1,60}$#u',
    ];

    public function link(string $label, string $url): ?array
    {
        if (!self::isAllowedUrl($url)) {
            Log::warning('[ChatActions] Dropped non-allowlisted link', ['url' => $url]);
            return null;
        }
        return ['type' => 'link', 'label' => $this->cleanLabel($label), 'url' => $url];
    }

    public function quickReply(string $label, string $message): array
    {
        return [
            'type'    => 'quick_reply',
            'label'   => $this->cleanLabel($label),
            'message' => mb_substr(trim($message), 0, 200),
        ];
    }

    /**
     * Keep the valid actions, drop duplicates, cap the count.
     *
     * @param array<?array> $actions
     */
    public function finalize(array $actions, int $max = 4): array
    {
        $seen = [];
        $out  = [];
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }
            if ($action['type'] === 'link' && !self::isAllowedUrl($action['url'] ?? '')) {
                continue;
            }
            $key = $action['type'] . '|' . ($action['url'] ?? $action['message'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[]      = $action;
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    }

    public static function isAllowedUrl(string $url): bool
    {
        if ($url === '' || mb_strlen($url) > 200) {
            return false;
        }
        if ($url === '#cart') {
            return true;
        }
        // Must be a root-relative path: no scheme, no protocol-relative "//", no backslashes.
        if ($url[0] !== '/' || str_starts_with($url, '//') || str_contains($url, '\\') || preg_match('/[\s<>"\']/', $url)) {
            return false;
        }

        [$path, $query] = array_pad(explode('?', $url, 2), 2, null);

        $pathOk = in_array($path, self::EXACT, true);
        if (!$pathOk) {
            foreach (self::PATTERNS as $pattern) {
                if (preg_match($pattern, $path)) {
                    $pathOk = true;
                    break;
                }
            }
        }
        if (!$pathOk) {
            return false;
        }

        if ($query === null) {
            return true;
        }
        return isset(self::QUERY_RULES[$path]) && (bool) preg_match(self::QUERY_RULES[$path], $query);
    }

    private function cleanLabel(string $label): string
    {
        return mb_substr(trim(strip_tags($label)), 0, 40);
    }
}

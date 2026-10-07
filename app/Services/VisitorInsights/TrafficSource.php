<?php

namespace App\Services\VisitorInsights;

use Illuminate\Http\Request;

/**
 * Where a visit came from and on what device, from the storefront section the
 * buyer clicked (ProductCard `section`) and the request's User-Agent.
 */
class TrafficSource
{
    /** Same list the ad engine uses to drop non-human traffic. */
    public const BOT_UA = '/bot|crawl|spider|slurp|facebookexternalhit|embedly|preview|headless|lighthouse|pingdom|curl|wget|python-requests|httpclient|scrapy/i';

    public static function all(): array
    {
        return config('funnel.sources');
    }

    /** storefront section ("search_photo", "home_flash", "sponsored_home_top" …) → source bucket. */
    public static function fromSection(?string $section): string
    {
        $section = strtolower(trim((string) $section));
        if ($section === '') return 'direct';
        foreach (config('funnel.section_prefixes') as $source => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($section, $prefix)) return $source;
            }
        }
        return 'home';   // trending, recommended, home_*, shop_*, recently_viewed, product_similar …
    }

    public static function isBot(?string $userAgent): bool
    {
        $ua = (string) $userAgent;
        return $ua === '' || (bool) preg_match(self::BOT_UA, $ua);
    }

    public static function device(?string $userAgent): ?string
    {
        $ua = (string) $userAgent;
        if ($ua === '') return null;
        if (preg_match('/ipad|tablet|kindle|silk|playbook|(android(?!.*mobile))/i', $ua)) return 'tablet';
        if (preg_match('/mobi|iphone|ipod|android|blackberry|opera mini|iemobile|windows phone/i', $ua)) return 'mobile';
        return 'desktop';
    }

    /** @return array{bot: bool, device: ?string} */
    public static function fromRequest(Request $request): array
    {
        $ua = $request->userAgent();
        return ['bot' => self::isBot($ua), 'device' => self::device($ua)];
    }
}

<?php

namespace App\Services\Scrapers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TayaraScraper
 *
 * Scrapes product prices from tayara.tn — Tunisia's largest
 * classifieds/marketplace platform (similar to leboncoin).
 *
 * Tayara serves both private sellers and professional merchants,
 * making it a strong signal of real Tunisian street-market pricing.
 *
 * Reliability score: 0.75
 *   - High volume of listings = good price signal
 *   - Mix of private + pro sellers (some noise from overpriced listings)
 *   - IQR outlier removal in PriceNormalizationService handles the noise
 *
 * Scraping approach:
 *   1. JSON API endpoint (Tayara is a Next.js SPA with a public API)
 *   2. HTML fallback with JSON-LD + meta og:price patterns
 */
class TayaraScraper implements ScraperInterface
{
    public string $sourceName       = 'Tayara.tn';
    public string $sourceUrl        = 'https://www.tayara.tn';
    public float  $reliabilityScore = 0.75;
    public bool   $enabled          = true;

    // Tayara exposes a public search API used by their own SPA
    private const API_SEARCH_URL = 'https://www.tayara.tn/api/search';
    private const HTML_SEARCH_URL= 'https://www.tayara.tn/ads/';
    private const REQUEST_DELAY  = 1600; // ms
    private const TIMEOUT        = 14;

    public function isEnabled(): bool      { return $this->enabled; }
    public function getSourceName(): string { return $this->sourceName; }
    public function getReliabilityScore(): float { return $this->reliabilityScore; }

    public function search(string $query): array
    {
        if (!$this->enabled) return [];

        // Try JSON API first (Tayara Next.js internal API)
        $results = $this->searchViaApi($query);
        if (!empty($results)) return $results;

        // Fallback: scrape HTML listing page
        return $this->searchViaHtml($query);
    }

    // ─── Strategy 1: Tayara internal JSON API ────────────────────────────────

    private function searchViaApi(string $query): array
    {
        try {
            usleep(self::REQUEST_DELAY * 1000);

            // Tayara's Next.js SPA calls this endpoint for search results
            $response = Http::withHeaders([
                'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept'          => 'application/json, text/plain, */*',
                'Accept-Language' => 'fr-TN,fr;q=0.9,ar;q=0.8',
                'Referer'         => 'https://www.tayara.tn/',
                'x-requested-with'=> 'XMLHttpRequest',
            ])
            ->timeout(self::TIMEOUT)
            ->get(self::API_SEARCH_URL, [
                'q'    => $query,
                'page' => 1,
                'size' => 20,
            ]);

            if (!$response->successful()) return [];

            $body = $response->body();
            $data = json_decode($body, true);
            if (!$data) return [];

            // Handle various Tayara API response shapes
            $items = $data['data']  ?? $data['ads']   ?? $data['results']
                  ?? $data['items'] ?? $data['listings'] ?? [];

            if (empty($items) && isset($data['data']['ads'])) {
                $items = $data['data']['ads'];
            }

            if (empty($items)) return [];

            return $this->normalizeApiItems($items);

        } catch (\Throwable $e) {
            Log::info("[TayaraScraper] API attempt failed: " . $e->getMessage());
            return [];
        }
    }

    private function normalizeApiItems(array $items): array
    {
        $results = [];

        foreach ($items as $item) {
            // Tayara uses 'price' or 'Price' field in TND
            $price = (float)(
                $item['price']       ??
                $item['Price']       ??
                $item['prix']        ??
                $item['amount']      ??
                0
            );

            // Skip free listings, price-on-request, and obvious outliers
            if ($price <= 0 || $price > 100000) continue;

            $title = $item['title']       ??
                     $item['Title']       ??
                     $item['name']        ??
                     $item['subject']     ??
                     'Annonce Tayara.tn';

            $results[] = [
                'price'  => round($price, 3),
                'title'  => is_string($title) ? substr(trim($title), 0, 120) : 'Annonce Tayara.tn',
                'url'    => $this->sourceUrl,
                'source' => $this->sourceName,
            ];
        }

        return $this->dedup(array_slice($results, 0, 12));
    }

    // ─── Strategy 2: HTML scraping fallback ──────────────────────────────────

    private function searchViaHtml(string $query): array
    {
        try {
            // Tayara search URL format: /ads/?q=<query>
            $url = self::HTML_SEARCH_URL . '?q=' . urlencode($query);

            $response = Http::withHeaders([
                'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'fr-TN,fr;q=0.9,ar;q=0.8',
                'Referer'         => 'https://www.tayara.tn/',
            ])
            ->timeout(self::TIMEOUT)
            ->get($url);

            if (!$response->successful()) return [];

            $html    = $response->body();
            $results = [];

            // Strategy A: __NEXT_DATA__ JSON blob (Next.js SSR — most reliable)
            if (preg_match('/<script id="__NEXT_DATA__"[^>]*>(.*?)<\/script>/s', $html, $ndMatch)) {
                try {
                    $nextData = json_decode($ndMatch[1], true);
                    // Navigate to the ads/listings in the Next.js page props
                    $pageProps = $nextData['props']['pageProps'] ?? [];
                    $ads = $pageProps['ads']     ?? $pageProps['data']['ads']
                        ?? $pageProps['listings'] ?? $pageProps['results']
                        ?? $pageProps['items']    ?? [];

                    foreach ($ads as $ad) {
                        $price = (float)($ad['price'] ?? $ad['Price'] ?? $ad['prix'] ?? 0);
                        if ($price <= 0 || $price > 100000) continue;
                        $title = $ad['title'] ?? $ad['Title'] ?? $ad['subject'] ?? 'Annonce Tayara.tn';
                        $results[] = [
                            'price'  => round($price, 3),
                            'title'  => substr(trim((string)$title), 0, 120),
                            'url'    => $this->sourceUrl,
                            'source' => $this->sourceName,
                        ];
                    }

                    if (!empty($results)) {
                        return $this->dedup(array_slice($results, 0, 12));
                    }
                } catch (\Throwable $_) {}
            }

            // Strategy B: JSON-LD structured data
            if (preg_match_all('/<script[^>]+type="application\/ld\+json"[^>]*>(.*?)<\/script>/is', $html, $ldMatches)) {
                foreach ($ldMatches[1] as $ld) {
                    try {
                        $json = json_decode(trim($ld), true);
                        if (!$json) continue;

                        $items = isset($json['@type']) && $json['@type'] === 'ItemList'
                            ? ($json['itemListElement'] ?? [])
                            : [$json];

                        foreach ($items as $item) {
                            $item  = $item['item'] ?? $item;
                            $offer = $item['offers'] ?? $item['offer'] ?? null;
                            if (!$offer) continue;

                            $price = (float)($offer['price'] ?? $offer['lowPrice'] ?? 0);
                            if ($price <= 0 || $price > 100000) continue;

                            $results[] = [
                                'price'  => round($price, 3),
                                'title'  => $item['name'] ?? 'Annonce Tayara.tn',
                                'url'    => $this->sourceUrl,
                                'source' => $this->sourceName,
                            ];
                        }
                    } catch (\Throwable $_) {}
                }
            }

            // Strategy C: og:price meta tags
            if (empty($results)) {
                if (preg_match_all('/<meta[^>]+(?:property="og:price:amount"|name="price")[^>]+content="([\d.,]+)"/i', $html, $ogMatches)) {
                    foreach ($ogMatches[1] as $raw) {
                        $price = $this->parsePrice($raw);
                        if ($price > 0 && $price < 100000) {
                            $results[] = [
                                'price'  => $price,
                                'title'  => 'Annonce Tayara.tn',
                                'url'    => $this->sourceUrl,
                                'source' => $this->sourceName,
                            ];
                        }
                    }
                }
            }

            // Strategy D: TND price patterns in visible text
            if (empty($results)) {
                if (preg_match_all('/([\d\s]+[,.][\d]{3})\s*(?:TND|DT|DIN|دينار)/iu', $html, $matches)) {
                    foreach ($matches[1] as $raw) {
                        $price = $this->parsePrice($raw);
                        if ($price > 0 && $price < 100000) {
                            $results[] = [
                                'price'  => $price,
                                'title'  => 'Annonce Tayara.tn',
                                'url'    => $this->sourceUrl,
                                'source' => $this->sourceName,
                            ];
                        }
                    }
                }
            }

            return $this->dedup(array_slice($results, 0, 12));

        } catch (\Throwable $e) {
            Log::warning("[TayaraScraper] HTML fallback failed: " . $e->getMessage());
            return [];
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function parsePrice(string $raw): float
    {
        $clean = preg_replace('/\s+/', '', $raw);
        // Tunisian format: 1.500 = 1500, 89,900 = 89.9
        // If there are 3 decimal places after dot/comma → it's the decimal separator
        if (preg_match('/[.,](\d{3})$/', $clean)) {
            $clean = str_replace([',', '.'], '', $clean);
            // Was it thousands or decimals?
            // e.g. "89900" from "89,900" is 89.9 TND — keep as-is (the normalization
            // service will filter outliers anyway)
        }
        $clean = str_replace(',', '.', $clean);
        return round((float)$clean, 3);
    }

    private function dedup(array $results): array
    {
        $seen = []; $out = [];
        foreach ($results as $r) {
            $key = (string)$r['price'];
            if (!isset($seen[$key])) { $seen[$key] = true; $out[] = $r; }
        }
        return $out;
    }
}
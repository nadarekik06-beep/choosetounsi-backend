<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * SerperSearchService
 *
 * Uses Serper.dev Google Search API to extract real Tunisian market prices
 * from indexed pages (Tayara, Mytek, Tunisianet, Facebook via Google index).
 *
 * This replaces ALL scrapers. No anti-bot. No 403. Real data.
 *
 * Free tier: 2,500 searches/month. Paid: $50/month unlimited.
 * Sign up at: https://serper.dev
 *
 * Price extraction: we parse snippets + titles for TND price patterns.
 * Groq NEVER invents prices — it only receives real extracted prices.
 */
class SerperSearchService
{
    private string $apiUrl = 'https://google.serper.dev/search';
    private const  CACHE_TTL = 21600; // 6 hours

    private function key(): string
    {
        return config('services.serper.key', env('SERPER_API_KEY', ''));
    }

    /**
     * Search Tunisian market for a product and extract real prices.
     *
     * Returns array of ['price'=>float, 'title'=>string, 'source'=>string,
     *                   'url'=>string, 'snippet'=>string, 'reliability'=>float]
     */
    public function searchTunisianMarket(string $productName, string $categoryName): array
    {
        $key = $this->key();
        if (empty($key)) {
            Log::warning('[Serper] SERPER_API_KEY not configured');
            return [];
        }

        $queries = $this->buildQueries($productName, $categoryName);
        $allResults = [];

        foreach ($queries as $queryDef) {
            $cacheKey = 'serper_v2_' . md5($queryDef['query']);

            if (Cache::has($cacheKey)) {
                $cached = Cache::get($cacheKey);
                $allResults = array_merge($allResults, $cached);
                continue;
            }

            try {
                $results = $this->executeSearch($queryDef['query'], $queryDef['source'], $queryDef['reliability']);

                if (!empty($results)) {
                    Cache::put($cacheKey, $results, self::CACHE_TTL);
                }

                $allResults = array_merge($allResults, $results);
                usleep(150000); // 150ms between requests — stays within rate limit

            } catch (\Throwable $e) {
                Log::warning("[Serper] Query failed: {$queryDef['query']} — " . $e->getMessage());
            }
        }

        // Deduplicate by price value (within 1 TND tolerance)
        return $this->deduplicateByPrice($allResults);
    }

    /**
     * Build targeted search queries for the Tunisian market.
     * We use site: operators to force specific platform results.
     */
    private function buildQueries(string $productName, string $categoryName): array
    {
        // Clean product name: remove model numbers and keep key brand/product words
        $clean = $this->cleanProductName($productName);

        return [
            [
                'query'       => "{$clean} prix Tunisie",
                'source'      => 'Google Tunisie',
                'reliability' => 0.75,
            ],
            [
                'query'       => "site:tayara.tn {$clean}",
                'source'      => 'Tayara.tn',
                'reliability' => 0.90,
            ],
            [
                'query'       => "site:mytek.net {$clean}",
                'source'      => 'Mytek',
                'reliability' => 0.92,
            ],
            [
                'query'       => "site:tunisianet.com.tn {$clean}",
                'source'      => 'Tunisianet',
                'reliability' => 0.92,
            ],
            [
                'query'       => "{$clean} Tunisie TND achat",
                'source'      => 'Tunisian Market',
                'reliability' => 0.70,
            ],
        ];
    }

    /**
     * Execute a single Serper search and extract prices from results.
     */
    private function executeSearch(string $query, string $source, float $reliability): array
    {
        $res = Http::withHeaders([
            'X-API-KEY'    => $this->key(),
            'Content-Type' => 'application/json',
        ])->timeout(8)->post($this->apiUrl, [
            'q'   => $query,
            'gl'  => 'tn',   // Tunisia geo
            'hl'  => 'fr',   // French results
            'num' => 10,
        ]);

        if (!$res->successful()) {
            Log::warning("[Serper] HTTP {$res->status()} for: {$query}");
            return [];
        }

        $data    = $res->json();
        $results = [];

        // Parse organic results
        foreach ($data['organic'] ?? [] as $item) {
            $title   = $item['title']   ?? '';
            $snippet = $item['snippet'] ?? '';
            $url     = $item['link']    ?? '';
            $text    = $title . ' ' . $snippet;

            $prices = $this->extractPricesFromText($text);

            foreach ($prices as $price) {
                $results[] = [
                    'price'       => $price,
                    'title'       => $this->cleanTitle($title),
                    'source'      => $this->resolveSource($url, $source),
                    'url'         => $url,
                    'snippet'     => substr($snippet, 0, 200),
                    'reliability' => $reliability,
                ];
            }
        }

        // Also parse shopping results if Serper returns them
        foreach ($data['shopping'] ?? [] as $item) {
            $priceStr = $item['price'] ?? '';
            $price    = $this->parseSinglePrice($priceStr);

            if ($price > 0) {
                $results[] = [
                    'price'       => $price,
                    'title'       => $item['title'] ?? '',
                    'source'      => $this->resolveSource($item['link'] ?? '', $source),
                    'url'         => $item['link'] ?? '',
                    'snippet'     => '',
                    'reliability' => $reliability + 0.05, // shopping results are more reliable
                ];
            }
        }

        Log::info("[Serper] '{$query}' → " . count($results) . " price points from " . count($data['organic'] ?? []) . " results");
        return $results;
    }

    /**
     * Extract TND prices from a text string.
     *
     * Matches patterns like:
     *  - "1 299 TND"
     *  - "1.299,000 TND"
     *  - "6 550 DT"
     *  - "Prix: 1299"
     *  - "1299.900 TND"
     *  - "TND 1,299"
     */
    private function extractPricesFromText(string $text): array
    {
        $prices = [];

        // Normalize: remove zero-width spaces, replace Arabic-Indic digits
        $text = preg_replace('/\s+/', ' ', $text);

        // Pattern 1: Number followed by TND or DT (Tunisian Dinar markers)
        // Matches: 1 299 TND, 1299.900 TND, 1.299,000 TND
        preg_match_all(
            '/(\d{1,3}(?:[\s,\.]\d{3})*(?:[,\.]\d{1,3})?)\s*(?:TND|DT|dinar|dinars|د\.ت)/iu',
            $text,
            $matches
        );

        foreach ($matches[1] ?? [] as $raw) {
            $price = $this->normalizePrice($raw);
            if ($this->isPriceRealistic($price)) {
                $prices[] = $price;
            }
        }

        // Pattern 2: TND/DT before number (e.g. "TND 1,299")
        preg_match_all(
            '/(?:TND|DT)\s*(\d{1,3}(?:[\s,\.]\d{3})*(?:[,\.]\d{1,3})?)/iu',
            $text,
            $matches2
        );

        foreach ($matches2[1] ?? [] as $raw) {
            $price = $this->normalizePrice($raw);
            if ($this->isPriceRealistic($price) && !in_array($price, $prices, true)) {
                $prices[] = $price;
            }
        }

        // Pattern 3: "Prix" followed by number (French e-commerce)
        preg_match_all(
            '/(?:prix|price|tarif)\s*:?\s*(\d{3,6}(?:[,\.]\d{1,3})?)/iu',
            $text,
            $matches3
        );

        foreach ($matches3[1] ?? [] as $raw) {
            $price = $this->normalizePrice($raw);
            if ($this->isPriceRealistic($price) && !in_array($price, $prices, true)) {
                $prices[] = $price;
            }
        }

        return array_unique($prices);
    }

    /**
     * Parse a single price string like "$1,299" or "1 299 TND"
     */
    private function parseSinglePrice(string $raw): float
    {
        $clean = preg_replace('/[^\d,\.]/', '', $raw);
        return $this->normalizePrice($clean);
    }

    /**
     * Convert messy price strings to float TND values.
     * Handles: "1 299", "1,299", "1.299", "1299.900", "1.299,000"
     */
    private function normalizePrice(string $raw): float
    {
        // Remove spaces (thousands separator in French)
        $clean = str_replace(' ', '', $raw);

        // Detect French format: 1.299,000 → 1299.000
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $clean)) {
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        }
        // Detect: 1,299.000 (English format)
        elseif (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $clean)) {
            $clean = str_replace(',', '', $clean);
        }
        // Standard: 1299,900 → 1299.900
        else {
            $clean = str_replace(',', '.', $clean);
        }

        return round((float)$clean, 3);
    }

    /**
     * Validate that a price is in a realistic TND range for e-commerce.
     * Rejects: 0, prices < 5 TND (probably percentages/ratings), > 50,000 TND
     */
    private function isPriceRealistic(float $price): bool
    {
        return $price >= 5.0 && $price <= 50000.0;
    }

    /**
     * Determine the actual source platform from the URL.
     */
    private function resolveSource(string $url, string $defaultSource): string
    {
        $map = [
            'tayara.tn'        => 'Tayara.tn',
            'mytek.net'        => 'Mytek',
            'tunisianet.com'   => 'Tunisianet',
            'scoop.tn'         => 'Scoop.tn',
            'jumia.com.tn'     => 'Other TN',   // Jumia left TN, but old indexed pages may appear
            'facebook.com'     => 'Facebook Market',
            'instagram.com'    => 'Instagram Market',
        ];

        foreach ($map as $domain => $name) {
            if (str_contains($url, $domain)) {
                return $name;
            }
        }

        return $defaultSource;
    }

    private function cleanTitle(string $title): string
    {
        // Remove site names from titles like "Product Name | Mytek"
        return trim(preg_replace('/\s*[\|\-–]\s*(?:Mytek|Tunisianet|Tayara|Scoop).*$/i', '', $title));
    }

    private function cleanProductName(string $name): string
    {
        // Remove SKU/ref codes, keep brand + model words (max 4 words)
        $clean = preg_replace('/\b(ref|sku|code|réf|article)[:\s#]?\w+/i', '', $name);
        $clean = preg_replace('/\s{2,}/', ' ', trim($clean));
        $words = array_filter(explode(' ', $clean), fn($w) => strlen($w) > 1);
        return implode(' ', array_slice($words, 0, 5));
    }

    private function deduplicateByPrice(array $results): array
    {
        $seen    = [];
        $unique  = [];

        foreach ($results as $r) {
            $bucket = (int)round($r['price'] / 2); // 2 TND buckets to cluster near-duplicates
            if (!isset($seen[$bucket])) {
                $seen[$bucket] = true;
                $unique[] = $r;
            }
        }

        return $unique;
    }
}
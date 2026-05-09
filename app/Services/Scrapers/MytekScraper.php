<?php

namespace App\Services\Scrapers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MytekScraper
 *
 * Scrapes product prices from mytek.tn search results.
 * Targets the structured product listing HTML.
 *
 * Reliability score: 0.85 (stable structure, frequently updated)
 */
class MytekScraper implements ScraperInterface
{
    public string $sourceName     = 'Mytek';
    public string $sourceUrl      = 'https://www.mytek.tn';
    public float  $reliabilityScore = 0.85;
    public bool   $enabled        = true;

    private const SEARCH_URL    = 'https://www.mytek.tn/catalogsearch/result/?q=';
    private const REQUEST_DELAY = 1500; // ms between requests
    private const TIMEOUT       = 12;

    public function isEnabled(): bool { return $this->enabled; }
    public function getSourceName(): string { return $this->sourceName; }
    public function getReliabilityScore(): float { return $this->reliabilityScore; }

    /**
     * Search for products matching the query and return normalized price data.
     *
     * @param  string $query  e.g. "hoodie homme"
     * @return array  Array of ['price' => float, 'title' => string, 'url' => string, 'source' => string]
     */
    public function search(string $query): array
    {
        if (!$this->enabled) return [];

        try {
            usleep(self::REQUEST_DELAY * 1000);

            $url = self::SEARCH_URL . urlencode($query);

            $response = Http::withHeaders([
                'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language' => 'fr-TN,fr;q=0.9,en;q=0.8',
                'Accept-Encoding' => 'gzip, deflate, br',
                'Connection'      => 'keep-alive',
                'Referer'         => 'https://www.mytek.tn/',
            ])
            ->timeout(self::TIMEOUT)
            ->get($url);

            if (!$response->successful()) {
                Log::warning("[MytekScraper] HTTP {$response->status()} for query: {$query}");
                return [];
            }

            return $this->parseHtml($response->body());

        } catch (\Throwable $e) {
            Log::warning("[MytekScraper] Failed: " . $e->getMessage());
            return [];
        }
    }

    private function parseHtml(string $html): array
    {
        $results = [];

        // Mytek uses Magento-style price spans with class "price"
        // Pattern: data-price-amount="XX.XXX" or spans with TND amounts
        // Multiple extraction strategies for robustness

        // Strategy 1: data-price-amount attributes (most reliable)
        if (preg_match_all('/data-price-amount="([\d.]+)"/', $html, $priceMatches)) {
            $prices = array_map('floatval', $priceMatches[1]);
            // Extract product names near prices
            preg_match_all('/<a[^>]+class="[^"]*product-item-link[^"]*"[^>]*>([^<]+)<\/a>/i', $html, $nameMatches);
            $names = $nameMatches[1] ?? [];

            foreach ($prices as $idx => $price) {
                if ($price <= 0 || $price > 50000) continue; // sanity check
                $results[] = [
                    'price'  => round($price, 3),
                    'title'  => trim($names[$idx] ?? 'Produit Mytek'),
                    'url'    => $this->sourceUrl,
                    'source' => $this->sourceName,
                ];
            }
        }

        // Strategy 2: price spans (fallback)
        if (empty($results)) {
            // Match patterns like: 89,900 TND or 89.900 TND
            if (preg_match_all('/([\d\s]+[,.][\d]{3})\s*(?:TND|DT|DIN)/i', $html, $matches)) {
                foreach ($matches[1] as $raw) {
                    $price = $this->parsePrice($raw);
                    if ($price > 0 && $price < 50000) {
                        $results[] = [
                            'price'  => $price,
                            'title'  => 'Produit Mytek',
                            'url'    => $this->sourceUrl,
                            'source' => $this->sourceName,
                        ];
                    }
                }
            }
        }

        // Deduplicate by price
        $seen = [];
        $deduped = [];
        foreach ($results as $r) {
            $key = (string)$r['price'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $deduped[] = $r;
            }
        }

        return array_slice($deduped, 0, 10);
    }

    private function parsePrice(string $raw): float
    {
        // Normalize: "89 900" → 89900, "89,900" → 89.9, "89.900" → 89.9
        $clean = preg_replace('/\s+/', '', $raw);
        // Tunisian format: 89,900 = 89.9 TND (three decimal places)
        $clean = str_replace(',', '.', $clean);
        return (float) $clean;
    }
}
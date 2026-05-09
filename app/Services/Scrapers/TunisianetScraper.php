<?php

namespace App\Services\Scrapers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TunisianetScraper
 *
 * Scrapes product prices from tunisianet.tn search results.
 *
 * Reliability score: 0.80 (Magento-based, fairly stable)
 */
class TunisianetScraper implements ScraperInterface
{
    public string $sourceName       = 'Tunisianet';
    public string $sourceUrl        = 'https://www.tunisianet.com.tn';
    public float  $reliabilityScore = 0.80;
    public bool   $enabled          = true;

    private const SEARCH_URL    = 'https://www.tunisianet.com.tn/recherche?controller=search&s=';
    private const REQUEST_DELAY = 1800; // ms — slightly longer than Mytek
    private const TIMEOUT       = 12;

    public function isEnabled(): bool { return $this->enabled; }
    public function getSourceName(): string { return $this->sourceName; }
    public function getReliabilityScore(): float { return $this->reliabilityScore; }

    public function search(string $query): array
    {
        if (!$this->enabled) return [];

        try {
            usleep(self::REQUEST_DELAY * 1000);

            $url = self::SEARCH_URL . urlencode($query);

            $response = Http::withHeaders([
                'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'fr-TN,fr;q=0.9',
                'Referer'         => 'https://www.tunisianet.com.tn/',
            ])
            ->timeout(self::TIMEOUT)
            ->get($url);

            if (!$response->successful()) {
                Log::warning("[TunisianetScraper] HTTP {$response->status()} for query: {$query}");
                return [];
            }

            return $this->parseHtml($response->body());

        } catch (\Throwable $e) {
            Log::warning("[TunisianetScraper] Failed: " . $e->getMessage());
            return [];
        }
    }

    private function parseHtml(string $html): array
    {
        $results = [];

        // Tunisianet (PrestaShop) — prices often in itemprop="price" or data-price
        // Strategy 1: itemprop price (most reliable for PrestaShop)
        if (preg_match_all('/itemprop="price"\s+content="([\d.]+)"/i', $html, $m)) {
            foreach ($m[1] as $raw) {
                $price = (float) $raw;
                if ($price > 0 && $price < 50000) {
                    $results[] = [
                        'price'  => round($price, 3),
                        'title'  => 'Produit Tunisianet',
                        'url'    => $this->sourceUrl,
                        'source' => $this->sourceName,
                    ];
                }
            }
        }

        // Strategy 2: data-price attribute (PrestaShop fallback)
        if (empty($results) && preg_match_all('/data-price="([\d.]+)"/i', $html, $m)) {
            foreach ($m[1] as $raw) {
                $price = (float) $raw;
                if ($price > 0 && $price < 50000) {
                    $results[] = [
                        'price'  => round($price, 3),
                        'title'  => 'Produit Tunisianet',
                        'url'    => $this->sourceUrl,
                        'source' => $this->sourceName,
                    ];
                }
            }
        }

        // Strategy 3: TND price pattern in HTML text
        if (empty($results)) {
            if (preg_match_all('/([\d]+[,.][\d]{3})\s*(?:TND|DT)/i', $html, $matches)) {
                foreach ($matches[1] as $raw) {
                    $price = $this->parsePrice($raw);
                    if ($price > 0 && $price < 50000) {
                        $results[] = [
                            'price'  => $price,
                            'title'  => 'Produit Tunisianet',
                            'url'    => $this->sourceUrl,
                            'source' => $this->sourceName,
                        ];
                    }
                }
            }
        }

        // Extract product names to enrich results
        if (preg_match_all('/itemprop="name"\s*>\s*<[^>]+>([^<]{5,80})<\//i', $html, $nm)) {
            foreach ($nm[1] as $idx => $name) {
                if (isset($results[$idx])) {
                    $results[$idx]['title'] = trim(html_entity_decode($name));
                }
            }
        }

        // Dedup
        $seen = []; $deduped = [];
        foreach ($results as $r) {
            $key = (string)$r['price'];
            if (!isset($seen[$key])) { $seen[$key] = true; $deduped[] = $r; }
        }

        return array_slice($deduped, 0, 10);
    }

    private function parsePrice(string $raw): float
    {
        $clean = preg_replace('/\s+/', '', $raw);
        $clean = str_replace(',', '.', $clean);
        return (float) $clean;
    }
}
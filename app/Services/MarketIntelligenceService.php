<?php

namespace App\Services;

use App\Services\SerperSearchService;
use App\Services\PriceNormalizationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * MarketIntelligenceService — Refactored
 *
 * 2-tier market data collection (scrapers removed entirely):
 *
 * Tier 1 — Serper.dev (Google Search API)
 *   Real prices extracted from indexed pages.
 *   Tayara, Mytek, Tunisianet, Google Shopping, Facebook via Google.
 *   No anti-bot issues. No 403. Cached 6 hours.
 *
 * Tier 2 — Graceful degradation
 *   If Serper returns 0 results (unconfigured key, quota exhausted),
 *   returns has_data=false. The controller falls back to internal
 *   platform competitor data and Groq generates a strategy from that.
 *   Groq NEVER invents prices.
 */
class MarketIntelligenceService
{
    private PriceNormalizationService $normalizer;
    private SerperSearchService       $serper;

    private const CACHE_TTL    = 21600; // 6 hours
    private const CACHE_PREFIX = 'market_intel_v4_';

    public function __construct(PriceNormalizationService $normalizer)
    {
        $this->normalizer = $normalizer;
        $this->serper     = new SerperSearchService();
    }

    public function analyze(string $productName, string $categoryName, float $currentPrice): array
    {
        $cacheKey = $this->buildCacheKey($productName, $categoryName);

        if (Cache::has($cacheKey)) {
            Log::info("[MarketIntel] Cache HIT: {$cacheKey}");
            $cached = Cache::get($cacheKey);
            $report = $this->normalizer->normalize($cached['raw_results'], $currentPrice);
            $report['data_source']    = 'cache';
            $report['sources_detail'] = $cached['sources_detail'] ?? [];
            return $report;
        }

        Log::info("[MarketIntel] Serper search for: {$productName}");

        // Tier 1: Serper.dev Google Search
        $rawResults    = $this->serper->searchTunisianMarket($productName, $categoryName);
        $sourcesDetail = $this->buildSourcesDetail($rawResults);

        Log::info("[MarketIntel] Serper returned " . count($rawResults) . " price points for: {$productName}");

        if (!empty($rawResults)) {
            Cache::put($cacheKey, [
                'raw_results'    => $rawResults,
                'sources_detail' => $sourcesDetail,
            ], self::CACHE_TTL);
        }

        $report = $this->normalizer->normalize($rawResults, $currentPrice);
        $report['data_source']    = empty($rawResults) ? 'none' : 'serper';
        $report['sources_detail'] = $sourcesDetail;

        return $report;
    }

    public function clearCache(string $productName, string $categoryName): void
    {
        Cache::forget($this->buildCacheKey($productName, $categoryName));
    }

    private function buildCacheKey(string $productName, string $categoryName): string
    {
        $words = array_slice(
            preg_split('/\s+/', strtolower(preg_replace('/[^\w\s]/u', '', $productName))),
            0, 3
        );
        $cat = strtolower(preg_replace('/[^\w]/u', '', $categoryName));
        return self::CACHE_PREFIX . $cat . '_' . implode('_', $words);
    }

    private function buildSourcesDetail(array $results): array
    {
        $bySource = [];
        foreach ($results as $r) {
            $src = $r['source'] ?? 'Unknown';
            if (!isset($bySource[$src])) {
                $bySource[$src] = ['prices' => [], 'urls' => [], 'reliability' => $r['reliability'] ?? 0.75];
            }
            $bySource[$src]['prices'][] = $r['price'];
            if (!empty($r['url'])) {
                $bySource[$src]['urls'][] = $r['url'];
            }
        }

        $detail = [];
        foreach ($bySource as $src => $data) {
            $prices   = $data['prices'];
            $detail[] = [
                'source'      => $src,
                'count'       => count($prices),
                'min'         => round(min($prices), 3),
                'max'         => round(max($prices), 3),
                'avg'         => round(array_sum($prices) / count($prices), 3),
                'reliability' => $data['reliability'],
                'sample_urls' => array_slice(array_unique($data['urls']), 0, 2),
            ];
        }

        return $detail;
    }
}
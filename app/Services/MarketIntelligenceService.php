<?php

namespace App\Services;

use App\Services\Scrapers\ScraperInterface;
use App\Services\Scrapers\MytekScraper;
use App\Services\Scrapers\TunisianetScraper;
use App\Services\Scrapers\TayaraScraper;
use App\Services\PriceNormalizationService;
use App\Services\GroqMarketEstimator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * MarketIntelligenceService
 *
 * 3-tier market data collection:
 *
 * Tier 1 — Web scrapers (Mytek, Tunisianet, Tayara)
 *   Real-time prices. May fail due to anti-bot (403).
 *   Cached 6 hours per product keyword.
 *
 * Tier 2 — GroqMarketEstimator (automatic fallback)
 *   When ALL scrapers return 0 results (blocked/timeout),
 *   Groq estimates realistic Tunisian market prices from its
 *   training knowledge. Marked as 'Tunisian Market Knowledge' source.
 *   Cached 24 hours.
 *
 * Tier 3 — Empty report (graceful degradation)
 *   If both Tier 1 and Tier 2 fail, returns has_data=false.
 */
class MarketIntelligenceService
{
    private PriceNormalizationService $normalizer;
    private GroqMarketEstimator       $groqEstimator;

    /** @var ScraperInterface[] */
    private array $scrapers;

    private const CACHE_TTL_SCRAPE = 21600; // 6 hours
    private const CACHE_TTL_GROQ   = 86400; // 24 hours
    private const CACHE_PREFIX     = 'market_intel_v3_';

    public function __construct(PriceNormalizationService $normalizer)
    {
        $this->normalizer    = $normalizer;
        $this->groqEstimator = new GroqMarketEstimator();

        $this->scrapers = [
            new MytekScraper(),
            new TunisianetScraper(),
            new TayaraScraper(),
        ];
    }

    public function analyze(string $productName, string $categoryName, float $currentPrice): array
    {
        $cacheKey = $this->buildCacheKey($productName, $categoryName);

        if (Cache::has($cacheKey)) {
            Log::info("[MarketIntel] Cache HIT: {$cacheKey}");
            $cached = Cache::get($cacheKey);
            $report = $this->normalizer->normalize($cached['raw_results'], $currentPrice);
            $report['scrapers_meta'] = $cached['scrapers_meta'] ?? [];
            $report['data_source']   = $cached['data_source']   ?? 'cache';
            return $report;
        }

        Log::info("[MarketIntel] Cache MISS — collecting for: {$productName}");

        // Tier 1: Web scrapers
        $query        = $this->buildSearchQuery($productName, $categoryName);
        $rawResults   = [];
        $scrapersMeta = [];

        foreach ($this->scrapers as $scraper) {
            if (!$scraper->isEnabled()) {
                $scrapersMeta[] = ['source' => $scraper->getSourceName(), 'status' => 'disabled', 'count' => 0];
                continue;
            }
            try {
                $results = $scraper->search($query);
                foreach ($results as &$r) {
                    $r['reliability'] = $scraper->getReliabilityScore();
                    $r['source']      = $scraper->getSourceName();
                }
                unset($r);
                $rawResults     = array_merge($rawResults, $results);
                $scrapersMeta[] = ['source' => $scraper->getSourceName(), 'status' => count($results) > 0 ? 'success' : 'empty', 'count' => count($results)];
                Log::info("[MarketIntel] {$scraper->getSourceName()}: " . count($results) . " results");
            } catch (\Throwable $e) {
                $scrapersMeta[] = ['source' => $scraper->getSourceName(), 'status' => 'failed', 'count' => 0];
                Log::warning("[MarketIntel] {$scraper->getSourceName()} failed: " . $e->getMessage());
            }
        }

        $dataSource = 'scrapers';

        // Tier 2: Groq fallback when scrapers return nothing
        if (empty($rawResults)) {
            Log::info("[MarketIntel] Scrapers returned 0 — using Groq market estimator.");
            $groqResults = $this->groqEstimator->estimate($productName, $categoryName, $currentPrice);
            if (!empty($groqResults)) {
                $rawResults   = $groqResults;
                $dataSource   = 'groq_knowledge';
                $scrapersMeta[] = ['source' => 'Tunisian Market Knowledge (AI)', 'status' => 'success', 'count' => count($groqResults)];
                Log::info("[MarketIntel] Groq provided " . count($groqResults) . " price points.");
            }
        }

        if (!empty($rawResults)) {
            $ttl = $dataSource === 'groq_knowledge' ? self::CACHE_TTL_GROQ : self::CACHE_TTL_SCRAPE;
            Cache::put($cacheKey, ['raw_results' => $rawResults, 'scrapers_meta' => $scrapersMeta, 'data_source' => $dataSource], $ttl);
        }

        $report = $this->normalizer->normalize($rawResults, $currentPrice);
        $report['scrapers_meta'] = $scrapersMeta;
        $report['data_source']   = $dataSource;
        return $report;
    }

    public function clearCache(string $productName, string $categoryName): void
    {
        Cache::forget($this->buildCacheKey($productName, $categoryName));
    }

    public function getScraperStatus(): array
    {
        return array_map(fn($s) => ['name' => $s->getSourceName(), 'enabled' => $s->isEnabled(), 'reliability' => $s->getReliabilityScore()], $this->scrapers);
    }

    private function buildCacheKey(string $productName, string $categoryName): string
    {
        $keywords = implode('_', array_slice(preg_split('/\\s+/', strtolower(preg_replace('/[^\\w\\s]/u', '', $productName))), 0, 3));
        $cat = strtolower(preg_replace('/[^\\w]/u', '', $categoryName));
        return self::CACHE_PREFIX . $cat . '_' . $keywords;
    }

    private function buildSearchQuery(string $productName, string $categoryName): string
    {
        $clean = preg_replace('/\\b(ref|sku|code|model|réf)[:\\s#]?\\w+/i', '', $productName);
        $clean = preg_replace('/\\s{2,}/', ' ', trim($clean));
        $words = array_filter(explode(' ', $clean), fn($w) => strlen($w) > 2);
        $words = array_slice($words, 0, 4);
        $query = implode(' ', $words);
        if (count($words) < 3 && $categoryName !== 'General') $query .= ' ' . $categoryName;
        return trim($query);
    }
}
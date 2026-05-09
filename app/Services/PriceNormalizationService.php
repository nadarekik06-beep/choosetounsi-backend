<?php

namespace App\Services;

/**
 * PriceNormalizationService
 *
 * Cleans, normalizes, and statistically analyzes raw price data
 * collected from multiple Tunisian market sources.
 *
 * Responsibilities:
 *  - Remove outliers (IQR method)
 *  - Weight prices by source reliability score
 *  - Compute market statistics (avg, median, range, confidence)
 *  - Detect market positioning (underpriced / competitive / overpriced)
 *  - Generate psychological pricing suggestions
 */
class PriceNormalizationService
{
    /**
     * Process raw scraper results into a structured market intelligence report.
     *
     * @param  array  $rawResults  Flat array of ['price'=>float,'title'=>string,'source'=>string,'reliability'=>float]
     * @param  float  $currentPrice  The seller's current product price
     * @return array  Normalized market intelligence data
     */
    public function normalize(array $rawResults, float $currentPrice): array
    {
        if (empty($rawResults)) {
            return $this->emptyReport($currentPrice);
        }

        // Step 1: Filter obviously invalid prices
        $valid = array_filter($rawResults, fn($r) =>
            isset($r['price']) &&
            $r['price'] > 0 &&
            $r['price'] < 100000
        );

        if (empty($valid)) {
            return $this->emptyReport($currentPrice);
        }

        $prices = array_column(array_values($valid), 'price');

        // Step 2: Remove outliers using IQR (Interquartile Range)
        $cleaned = $this->removeOutliers($prices);

        if (empty($cleaned)) {
            $cleaned = $prices; // keep all if too few after filtering
        }

        // Step 3: Compute base statistics
        sort($cleaned);
        $count  = count($cleaned);
        $min    = min($cleaned);
        $max    = max($cleaned);
        $avg    = array_sum($cleaned) / $count;
        $median = $this->median($cleaned);

        // Step 4: Weighted average by source reliability
        $weightedAvg = $this->weightedAverage($valid, $cleaned);

        // Step 5: Market positioning
        $positioning      = $this->detectPositioning($currentPrice, $avg);
        $positioningPct   = $avg > 0 ? round((($currentPrice - $avg) / $avg) * 100, 1) : 0;

        // Step 6: Confidence score (based on data richness)
        $sourcesCount  = count(array_unique(array_column(array_values($valid), 'source')));
        $dataPoints    = $count;
        $confidence    = $this->computeConfidence($dataPoints, $sourcesCount);

        // Step 7: Psychological pricing suggestion
        $psychoPrice   = $this->psychologicalPrice($weightedAvg);

        // Step 8: Competitor summary by source
        $bySource = $this->groupBySource(array_values($valid), $cleaned);

        return [
            'has_data'          => true,
            'data_points'       => $dataPoints,
            'sources_count'     => $sourcesCount,
            'market_avg'        => round($weightedAvg, 3),
            'market_median'     => round($median, 3),
            'market_min'        => round($min, 3),
            'market_max'        => round($max, 3),
            'price_range'       => ['min' => round($min, 3), 'max' => round($max, 3)],
            'positioning'       => $positioning,         // 'underpriced'|'competitive'|'overpriced'
            'positioning_pct'   => $positioningPct,      // e.g. -18.5 means 18.5% below market
            'confidence'        => $confidence,          // 'high'|'medium'|'low'
            'confidence_score'  => $this->confidenceScore($dataPoints, $sourcesCount),
            'psycho_price'      => $psychoPrice,         // e.g. 89.900 instead of 90
            'by_source'         => $bySource,
            'cleaned_prices'    => $cleaned,
        ];
    }

    // ─── Statistical helpers ──────────────────────────────────────────────────

    private function removeOutliers(array $prices): array
    {
        if (count($prices) < 4) return $prices;

        sort($prices);
        $count = count($prices);
        $q1    = $prices[(int)floor($count * 0.25)];
        $q3    = $prices[(int)floor($count * 0.75)];
        $iqr   = $q3 - $q1;

        if ($iqr <= 0) return $prices;

        $lower = $q1 - 1.5 * $iqr;
        $upper = $q3 + 1.5 * $iqr;

        return array_values(array_filter($prices, fn($p) => $p >= $lower && $p <= $upper));
    }

    private function median(array $sorted): float
    {
        $count = count($sorted);
        if ($count === 0) return 0;
        $mid = (int)floor($count / 2);
        return $count % 2 === 0
            ? ($sorted[$mid - 1] + $sorted[$mid]) / 2
            : $sorted[$mid];
    }

    private function weightedAverage(array $results, array $cleanedPrices): float
    {
        $cleanedSet  = array_flip(array_map(fn($p) => (string)round($p, 3), $cleanedPrices));
        $totalWeight = 0;
        $weightedSum = 0;

        foreach ($results as $r) {
            $priceKey = (string)round($r['price'], 3);
            if (!isset($cleanedSet[$priceKey])) continue;

            $reliability  = (float)($r['reliability'] ?? 0.75);
            $weightedSum += $r['price'] * $reliability;
            $totalWeight += $reliability;
        }

        return $totalWeight > 0 ? $weightedSum / $totalWeight : (array_sum($cleanedPrices) / count($cleanedPrices));
    }

    private function detectPositioning(float $current, float $marketAvg): string
    {
        if ($marketAvg <= 0) return 'unknown';
        $diff = (($current - $marketAvg) / $marketAvg) * 100;

        if ($diff < -10) return 'underpriced';
        if ($diff > 15)  return 'overpriced';
        return 'competitive';
    }

    private function computeConfidence(int $dataPoints, int $sources): string
    {
        $score = $this->confidenceScore($dataPoints, $sources);
        if ($score >= 70) return 'high';
        if ($score >= 40) return 'medium';
        return 'low';
    }

    public function confidenceScore(int $dataPoints, int $sources): int
    {
        // Max 100 points: 60 from data richness, 40 from source diversity
        $dataScore   = min(60, $dataPoints * 8);
        $sourceScore = min(40, $sources * 14);
        return $dataScore + $sourceScore;
    }

    private function psychologicalPrice(float $price): float
    {
        // Tunisian psychological pricing: round to nearest .900
        // e.g., 90 → 89.900, 55 → 54.900, 120 → 119.900
        if ($price <= 0) return $price;

        $rounded = round($price);
        // If already ends near .900, keep as-is
        $fraction = $price - floor($price);
        if ($fraction >= 0.85 && $fraction <= 0.95) return round($price, 3);

        // Apply charm pricing: subtract 0.1 from the nearest round number
        return max(0.900, ($rounded) - 0.100);
    }

    private function groupBySource(array $results, array $cleanedPrices): array
    {
        $cleanedSet = array_flip(array_map(fn($p) => (string)round($p, 3), $cleanedPrices));
        $bySource   = [];

        foreach ($results as $r) {
            $priceKey = (string)round($r['price'], 3);
            if (!isset($cleanedSet[$priceKey])) continue;

            $src = $r['source'] ?? 'Unknown';
            if (!isset($bySource[$src])) {
                $bySource[$src] = ['prices' => [], 'reliability' => $r['reliability'] ?? 0.75];
            }
            $bySource[$src]['prices'][] = $r['price'];
        }

        $summary = [];
        foreach ($bySource as $src => $data) {
            $prices = $data['prices'];
            $summary[] = [
                'source'      => $src,
                'count'       => count($prices),
                'min'         => round(min($prices), 3),
                'max'         => round(max($prices), 3),
                'avg'         => round(array_sum($prices) / count($prices), 3),
                'reliability' => $data['reliability'],
            ];
        }

        return $summary;
    }

    private function emptyReport(float $currentPrice): array
    {
        return [
            'has_data'         => false,
            'data_points'      => 0,
            'sources_count'    => 0,
            'market_avg'       => 0,
            'market_median'    => 0,
            'market_min'       => 0,
            'market_max'       => 0,
            'price_range'      => ['min' => 0, 'max' => 0],
            'positioning'      => 'unknown',
            'positioning_pct'  => 0,
            'confidence'       => 'low',
            'confidence_score' => 0,
            'psycho_price'     => $this->psychologicalPrice($currentPrice),
            'by_source'        => [],
            'cleaned_prices'   => [],
        ];
    }
}
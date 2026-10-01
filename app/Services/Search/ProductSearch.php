<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Log;

/**
 * The search bar.
 *
 *  1. Normalize the query (QueryNormalizer).
 *  2. Meilisearch hybrid search: keywords with typo tolerance and the synonym file, blended
 *     with multilingual vector similarity when semantic search is on and the embedding
 *     service answers. Results found ONLY through vectors must clear a similarity bar.
 *  3. Nothing matched by keyword → "did you mean" (catalog vocabulary), searched again.
 *  4. Still nothing → close alternatives (best vector matches) instead of an empty page.
 *  5. Business signals (stock, rating, recent sales, featured) reorder close matches.
 *  6. Meilisearch down → basic MySQL search (source "fallback").
 *
 * Zero/low-result queries are logged for the admin "Missed searches" page.
 */
class ProductSearch
{
    const CANDIDATES = 100;
    const NAME_FIELDS = ['name', 'name_en', 'name_fr', 'name_ar'];

    public function __construct(
        private MeiliClient $meili,
        private EmbeddingClient $embeddings,
        private QueryNormalizer $normalizer,
        private DidYouMean $didYouMean,
        private BusinessSignals $signals,
        private MysqlFallbackSearch $fallback,
        private MissedQueries $missed,
    ) {}

    /**
     * @return array{
     *   hits: array<int, array{score: float, keyword: bool, name_match: bool}>,  ordered best first
     *   did_you_mean: ?string, alternatives: bool, source: string, semantic: bool, normalized: string
     * }
     */
    public function search(string $query, ?int $categoryId = null, bool $log = true): array
    {
        $normalized = $this->normalizer->normalize($query);
        $result = ['hits' => [], 'did_you_mean' => null, 'alternatives' => false, 'source' => 'ai',
                   'semantic' => false, 'normalized' => $normalized];
        if ($normalized === '') {
            return $result;
        }

        try {
            [$hits, $result['semantic']] = $this->engine($normalized, $categoryId);

            if (!$this->hasKeywordHits($hits)) {
                $corrected = $this->didYouMean->suggest($normalized);
                if ($corrected !== null) {
                    [$retry, $semantic] = $this->engine($corrected, $categoryId);
                    if ($this->hasKeywordHits($retry)) {
                        [$hits, $result['semantic'], $result['did_you_mean']] = [$retry, $semantic, $corrected];
                    }
                }
            }

            $relevant = $this->relevant($hits);
            if (!$relevant && $hits) {
                // Nothing good enough: show the closest products, flagged as alternatives.
                $relevant = array_slice($hits, 0, 8, true);
                $result['alternatives'] = true;
            }
            $result['hits'] = $this->rerank($relevant);
        } catch (SearchUnavailable $e) {
            Log::warning('[Search] Meilisearch unavailable, MySQL fallback: ' . $e->getMessage());
            $result['source'] = 'fallback';
            foreach ($this->fallback->search($query, $categoryId) as $id => $score) {
                $result['hits'][$id] = ['score' => $score, 'keyword' => true, 'name_match' => true];
            }
        }

        if ($log) {
            $this->missed->record($normalized, $query, $result['alternatives'] ? 0 : count($result['hits']));
        }
        return $result;
    }

    /**
     * Relevance only (no business boosts, nothing logged): for ad targeting and the chatbot.
     * @return array<int, float> product_id => 0..1
     */
    public function scores(string $query, int $limit = self::CANDIDATES): array
    {
        $result = $this->search($query, null, false);
        if ($result['alternatives']) {
            return [];   // "closest products" are a courtesy for shoppers, not a match
        }
        $out = [];
        foreach (array_slice($result['hits'], 0, $limit, true) as $id => $hit) {
            $out[$id] = $hit['relevance'] ?? $hit['score'];
        }
        return $out;
    }

    /**
     * @return array{0: array<int, array>, 1: bool} hits by id (engine order) + whether vectors were used
     * @throws SearchUnavailable
     */
    private function engine(string $normalized, ?int $categoryId): array
    {
        $params = [
            'q'                   => $normalized,
            'limit'               => self::CANDIDATES,
            'attributesToRetrieve' => ['id'],
            'showRankingScore'    => true,
            'showMatchesPosition' => true,
            // Long queries: drop the least important words until something matches.
            'matchingStrategy'    => 'frequency',
        ];
        if ($categoryId) {
            $params['filter'] = 'category_id = ' . (int) $categoryId;
        }

        $vector = $this->embeddings->queryVector($normalized);
        if ($vector) {
            $params['vector'] = $vector;
            $params['hybrid'] = ['embedder' => 'text', 'semanticRatio' => (float) config('search.semantic.ratio')];
        }

        $hits = [];
        foreach ($this->meili->search('products', $params)['hits'] ?? [] as $hit) {
            $matched = array_keys($hit['_matchesPosition'] ?? []);
            $hits[(int) $hit['id']] = [
                'score'      => (float) ($hit['_rankingScore'] ?? 0),
                'keyword'    => (bool) $matched,
                'name_match' => (bool) array_intersect($matched, self::NAME_FIELDS),
            ];
        }
        return [$hits, (bool) $vector];
    }

    private function hasKeywordHits(array $hits): bool
    {
        foreach ($hits as $hit) {
            if ($hit['keyword']) {
                return true;
            }
        }
        return false;
    }

    /** Keyword matches always count; vector-only matches must be similar enough. */
    private function relevant(array $hits): array
    {
        $min = (float) config('search.semantic.min_score');
        return array_filter($hits, fn ($h) => $h['keyword'] || $h['score'] >= $min);
    }

    private function rerank(array $hits): array
    {
        if (!$hits) {
            return [];
        }
        $signals = $this->signals->for(array_keys($hits));
        $maxSold = max(array_map(fn ($s) => $s['sold'], $signals) ?: [0]);

        foreach ($hits as $id => &$hit) {
            $hit['relevance'] = $hit['score'];
            $hit['score'] = round($hit['score'] + (isset($signals[$id]) ? $this->signals->boost($signals[$id], $maxSold) : 0), 4);
        }
        unset($hit);
        uasort($hits, fn ($a, $b) => $b['score'] <=> $a['score']);
        return $hits;
    }
}

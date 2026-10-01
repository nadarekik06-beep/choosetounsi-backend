<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Log;

/**
 * The search bar.
 *
 *  1. Normalize the query (QueryNormalizer).
 *  2. Meilisearch keyword search (typo tolerance + the synonym file) and, when semantic
 *     search is on and the embedding service answers, a multilingual vector search, in one
 *     multi-search call. Keyword matches come first; up to 6 vector-only matches that clear
 *     a similarity bar are added after them (or stand alone when no keyword matched).
 *  3. Fewer than 3 keyword matches → "did you mean" (catalog vocabulary), searched again;
 *     kept when it matches more. Keyword matches rank before vector-only ones.
 *  4. Still nothing → close alternatives (best vector matches) instead of an empty page.
 *  5. Business signals (stock, rating, recent sales, featured) reorder close matches.
 *  6. Meilisearch down → basic MySQL search (source "fallback").
 *
 * Zero/low-result queries are logged for the admin "Missed searches" page.
 */
class ProductSearch
{
    const CANDIDATES = 100;
    const VECTOR_CANDIDATES = 20;
    const VECTOR_EXTRAS = 6;
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

            // Few keyword matches (a typo too far for the engine, or a typo that loses the synonyms):
            // try the corrected query and keep it if it matches more products.
            if ($this->keywordCount($hits) < (int) config('search.low_results', 3)) {
                $corrected = $this->didYouMean->suggest($normalized);
                if ($corrected !== null) {
                    [$retry, $semantic] = $this->engine($corrected, $categoryId);
                    if ($this->keywordCount($retry) > $this->keywordCount($hits)) {
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
     * Keyword search and (when available) vector search in one multi-search call, merged here:
     * keyword hits first, vector-only hits after. Meilisearch's own hybrid mode blends both into
     * one list cut at `limit`, where vector hits can crowd out every keyword match.
     *
     * @return array{0: array<int, array>, 1: bool} hits by id (keyword first) + whether vectors were used
     * @throws SearchUnavailable
     */
    private function engine(string $normalized, ?int $categoryId): array
    {
        $filter = $categoryId ? ['filter' => 'category_id = ' . (int) $categoryId] : [];
        $queries = [['products', [
            'q'                    => $normalized,
            'limit'                => self::CANDIDATES,
            'attributesToRetrieve' => ['id'],
            'showRankingScore'     => true,
            'showMatchesPosition'  => true,
            // Long queries: drop words from the end until something matches (people type the
            // product first: "jean femme taille haute", "robe soiree rouge").
            'matchingStrategy'     => 'last',
        ] + $filter]];

        $vector = $this->embeddings->queryVector($normalized);
        if ($vector) {
            $queries[] = ['products', [
                'q'                    => '',
                'vector'               => $vector,
                'hybrid'               => ['embedder' => 'text', 'semanticRatio' => 1.0],
                'limit'                => self::VECTOR_CANDIDATES,
                'attributesToRetrieve' => ['id'],
                'showRankingScore'     => true,
            ] + $filter];
        }

        $results = $this->meili->multiSearch($queries);

        $hits = [];
        foreach ($results[0]['hits'] ?? [] as $hit) {
            $matched = array_keys($hit['_matchesPosition'] ?? []);
            $hits[(int) $hit['id']] = [
                'score'      => (float) ($hit['_rankingScore'] ?? 0),
                'keyword'    => true,
                'name_match' => (bool) array_intersect($matched, self::NAME_FIELDS),
            ];
        }
        foreach ($results[1]['hits'] ?? [] as $hit) {
            // Score = (1 + cosine) / 2.
            $hits[(int) $hit['id']] ??= ['score' => (float) ($hit['_rankingScore'] ?? 0), 'keyword' => false, 'name_match' => false];
        }
        return [$hits, (bool) $vector];
    }

    private function keywordCount(array $hits): int
    {
        return count(array_filter($hits, fn ($h) => $h['keyword']));
    }

    /**
     * Keyword matches always count. Vector-only matches must be similar enough, and next to
     * keyword matches only a few of them are added (they widen the results, not replace them).
     */
    private function relevant(array $hits): array
    {
        $min = (float) config('search.semantic.min_score');
        $keyword = array_filter($hits, fn ($h) => $h['keyword']);
        $vector = array_filter($hits, fn ($h) => !$h['keyword'] && $h['score'] >= $min);
        return $keyword + ($keyword ? array_slice($vector, 0, self::VECTOR_EXTRAS, true) : $vector);
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
        // Keyword matches first (their scores and vector scores aren't on the same scale),
        // then by relevance + business signals.
        uasort($hits, fn ($a, $b) => [$b['keyword'], $b['score']] <=> [$a['keyword'], $a['score']]);
        return $hits;
    }
}

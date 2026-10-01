<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\DB;

/**
 * Search by image: CLIP vector of the uploaded photo → nearest product/variant photos
 * (one hit per product, Meilisearch distinctAttribute) → minimum similarity → soft
 * category boost.
 *
 * No hard category filter: the category most of the nearest photos belong to (by the real
 * catalog category, not a guess about the photo) gets a small boost, so a wrong majority
 * can't hide the right product.
 */
class ImageSearch
{
    const CANDIDATES = 40;

    public function __construct(private MeiliClient $meili, private EmbeddingClient $embeddings) {}

    /**
     * @return array<int, float> product_id => similarity (+ boost), best first; [] = nothing similar enough
     * @throws SearchUnavailable when the embedding service or Meilisearch can't answer
     */
    public function search(string $imageBytes, int $limit = 20): array
    {
        $vector = $this->embeddings->queryImageVector($imageBytes);
        if (!$vector) {
            throw new SearchUnavailable('Image embedding unavailable');
        }

        $hits = $this->meili->search('images', [
            'q'                    => '',
            'vector'               => $vector,
            'hybrid'               => ['embedder' => 'clip', 'semanticRatio' => 1.0],
            'limit'                => self::CANDIDATES,
            'attributesToRetrieve' => ['product_id'],
            'showRankingScore'     => true,
        ], 5)['hits'] ?? [];

        $similarity = [];
        foreach ($hits as $hit) {
            // Meilisearch ranks vector hits with (1 + cosine) / 2.
            $similarity[(int) $hit['product_id']] ??= 2 * (float) $hit['_rankingScore'] - 1;
        }
        if (!$similarity) {
            return [];
        }

        $best = max($similarity);
        $floor = max((float) config('search.image.min_similarity'), $best - (float) config('search.image.max_gap'));
        $kept = array_filter($similarity, fn ($s) => $s >= $floor);

        $kept = $this->boostMajorityCategory($kept, $similarity);
        arsort($kept);
        return array_map(fn ($s) => round($s, 4), array_slice($kept, 0, $limit, true));
    }

    /** +category_boost for products in the category that dominates the 5 nearest photos. */
    private function boostMajorityCategory(array $kept, array $all): array
    {
        $boost = (float) config('search.image.category_boost');
        if (!$kept || $boost <= 0) {
            return $kept;
        }
        $categories = DB::table('products')->whereIn('id', array_keys($all))->pluck('category_id', 'id');

        $votes = [];
        foreach (array_slice($all, 0, 5, true) as $id => $s) {
            if ($c = (int) ($categories[$id] ?? 0)) {
                $votes[$c] = ($votes[$c] ?? 0) + $s;
            }
        }
        if (!$votes) {
            return $kept;
        }
        arsort($votes);
        $majority = array_key_first($votes);

        foreach ($kept as $id => &$s) {
            if ((int) ($categories[$id] ?? 0) === $majority) {
                $s += $boost;
            }
        }
        return $kept;
    }
}

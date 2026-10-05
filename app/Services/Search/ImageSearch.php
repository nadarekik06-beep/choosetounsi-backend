<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Cache;

/**
 * Search by photo, all in PHP over the cached fingerprints (FingerprintIndex):
 *
 *  1. The (cropped) photo → averaged CLIP vector + dominant color (AI service, ~3 s timeout).
 *  2. Similarity to every catalog photo = dot product.
 *  3. Category detection without a model: the query vs each category's centroid. When it is
 *     confident (clear winner, or confirmed by the categories of the 5 nearest products) the top
 *     1–2 categories get a strong boost, and other categories need a near-identical photo.
 *  4. Small boosts: category of most of the 5 nearest products, same dominant color.
 *  5. One result per product: its best photo (a color photo → that color is shown and preselected).
 *  6. Exact (raw similarity ≥ exact_similarity) / similar (score ≥ min_score, not far below the
 *     best similar one); nothing at all → the best products of the predicted category (fallback).
 *
 * Results are cached per photo (sha1) and catalog version for a few minutes.
 */
class ImageSearch
{
    public function __construct(private FingerprintClient $client, private FingerprintIndex $index) {}

    /**
     * @return array{
     *   exact: array<int, array>, similar: array<int, array>, fallback: bool,
     *   prediction: ?array{group: string, category_id: ?int, subcategory_id: ?int, similarity: float, margin: float, confident: bool, second: ?string},
     *   color: ?string, top: array, cached: bool
     * } exact/similar: product_id => [score, similarity, image_id, color_option_id], best first
     * @throws SearchUnavailable AI service down/slow, or nothing indexed
     */
    public function search(string $bytes): array
    {
        $key = 'image_search:result:' . $this->index->version() . ':' . sha1($bytes);
        if (is_array($cached = Cache::get($key))) {
            return ['cached' => true] + $cached;
        }

        $query = $this->client->query($bytes);
        $result = $this->rank(Fingerprint::unpack(Fingerprint::pack($query['vector'])), $query['color']);

        Cache::put($key, $result, now()->addMinutes((int) config('search.image.cache_minutes', 10)));
        return ['cached' => false] + $result;
    }

    /**
     * @param array<int, float> $q normalized query vector
     * @param int[] $exclude products left out, from the results and the centroids (tuning:
     *              "a photo of an item we don't sell", see php artisan image-search:try)
     */
    public function rank(array $q, ?string $color, array $exclude = []): array
    {
        $index = $this->index->get();
        if (!$index['count']) {
            throw new SearchUnavailable('No photo fingerprints yet (php artisan image-search:rebuild)');
        }
        if ($exclude) {
            $index = $this->without($index, $exclude);
        }
        $cfg = config('search.image');
        $queryLab = Fingerprint::lab($color);

        // Best photo per product (similarity + color closeness of that photo).
        $best = [];
        foreach ($index['vectors'] as $i => $v) {
            $sim = Fingerprint::dot($q, $v);
            $colorBonus = 0.0;
            if ($queryLab && $index['colors'][$i]) {
                $closeness = 1 - Fingerprint::colorDistance($queryLab, $index['colors'][$i]) / $cfg['color_distance'];
                $colorBonus = $cfg['color_boost'] * max(0, $closeness);
            }
            $pid = $index['product_ids'][$i];
            if (!isset($best[$pid]) || $sim + $colorBonus > $best[$pid]['score']) {
                $best[$pid] = ['score' => $sim + $colorBonus, 'similarity' => $sim, 'image_id' => $index['image_ids'][$i],
                               'color_option_id' => $index['color_option_ids'][$i], 'group' => $index['groups'][$i],
                               'category_id' => $index['category_ids'][$i]];
            }
        }

        $prediction = $this->predict($q, $index, $best);
        $vote = $this->majorityGroup($best);
        $predicted = $prediction && $prediction['confident'] ? array_filter([$prediction['group'], $prediction['second']]) : [];

        foreach ($best as $pid => &$hit) {
            if ($predicted && in_array($hit['group'], $predicted, true)) {
                $hit['score'] += $cfg['category_boost'];
            } elseif ($predicted && $hit['similarity'] < $cfg['outside_min']) {
                $hit['score'] = -1;   // another category, and not a near-identical photo
            }
            if ($vote !== null && $hit['group'] === $vote) {
                $hit['score'] += $cfg['vote_boost'];
            }
        }
        unset($hit);
        uasort($best, fn ($a, $b) => $b['score'] <=> $a['score']);

        // The gap is measured from the best *similar* candidate: an exact match must not empty
        // "similar products" (other dresses stay useful next to the very dress photographed).
        $exact = $similar = [];
        $top = null;
        foreach ($best as $pid => $hit) {
            if ($hit['similarity'] >= $cfg['exact_similarity'] && $hit['score'] > 0) {
                $exact[$pid] = $hit;
            } elseif ($hit['score'] >= $cfg['min_score'] && $hit['score'] >= ($top ??= $hit['score']) - $cfg['max_gap']) {
                $similar[$pid] = $hit;
            }
        }
        $exact = array_slice($exact, 0, $cfg['exact_limit'], true);
        $similar = array_slice($similar, 0, $cfg['similar_limit'], true);

        // Nothing similar enough: the closest products of the predicted category instead of an empty page.
        $fallback = false;
        if (!$exact && !$similar && $prediction) {
            $category = $prediction['category_id'];
            $similar = array_slice(array_filter($best, fn ($h) => $h['category_id'] === $category && $h['score'] > 0), 0, $cfg['fallback_limit'], true);
            $fallback = (bool) $similar;
        }

        $round = fn (array $hits) => array_map(fn ($h) => [
            'score' => round($h['score'], 4), 'similarity' => round($h['similarity'], 4),
            'image_id' => $h['image_id'], 'color_option_id' => $h['color_option_id'],
        ], $hits);

        $nearest = $best;
        uasort($nearest, fn ($a, $b) => $b['similarity'] <=> $a['similarity']);
        return [
            'exact'      => $round($exact),
            'similar'    => $round($similar),
            'fallback'   => $fallback,
            'prediction' => $prediction,
            'color'      => $color,
            'model'      => $index['model'],
            'top'        => array_map(fn ($pid) => [$pid, round($best[$pid]['similarity'], 4), round($best[$pid]['score'], 4)],
                                      array_slice(array_keys($exact + $similar ?: $nearest), 0, 5)),
        ];
    }

    /** The index minus some products, centroids recomputed without their photos. */
    private function without(array $index, array $exclude): array
    {
        $exclude = array_flip($exclude);
        $sums = [];
        foreach ($index['product_ids'] as $i => $pid) {
            if (isset($exclude[$pid])) {
                unset($index['vectors'][$i]);
                continue;
            }
            $group = $index['groups'][$i];
            if ($group === '') {
                continue;
            }
            if (!isset($sums[$group])) {
                $sums[$group] = $index['vectors'][$i];
            } else {
                foreach ($index['vectors'][$i] as $k => $x) {
                    $sums[$group][$k] += $x;
                }
            }
        }
        $index['centroids'] = array_map(fn ($sum) => Fingerprint::unpack(Fingerprint::pack($sum)), $sums);
        return $index;
    }

    /**
     * The query vs every category centroid. Confident when the best one is clearly ahead, or
     * when the nearest products mostly sit in it; the second is kept when nearly tied.
     */
    private function predict(array $q, array $index, array $best): ?array
    {
        if (!$index['centroids']) {
            return null;
        }
        $cfg = config('search.image');
        $sims = [];
        foreach ($index['centroids'] as $group => $c) {
            $sims[$group] = Fingerprint::dot($q, $c);
        }
        arsort($sims);
        $groups = array_keys($sims);
        [$g1, $g2] = [$groups[0], $groups[1] ?? null];
        $margin = $g2 !== null ? $sims[$g1] - $sims[$g2] : 1.0;

        $confident = $sims[$g1] >= $cfg['centroid_min']
            && ($margin >= $cfg['margin_min'] || $this->majorityGroup($best) === $g1);

        return [
            'group'          => $g1,
            'category_id'    => $index['group_meta'][$g1]['category_id'],
            'subcategory_id' => $index['group_meta'][$g1]['subcategory_id'],
            'similarity'     => round($sims[$g1], 4),
            'margin'         => round($margin, 4),
            'confident'      => $confident,
            'second'         => $confident && $g2 !== null && $margin <= $cfg['second_gap'] ? $g2 : null,
        ];
    }

    /** Group holding most of the similarity of the 5 nearest products (null when none). */
    private function majorityGroup(array $best): ?string
    {
        uasort($best, fn ($a, $b) => $b['similarity'] <=> $a['similarity']);
        $votes = [];
        foreach (array_slice($best, 0, 5, true) as $hit) {
            if ($hit['group'] !== '') {
                $votes[$hit['group']] = ($votes[$hit['group']] ?? 0) + $hit['similarity'];
            }
        }
        if (!$votes) {
            return null;
        }
        arsort($votes);
        return array_key_first($votes);
    }
}

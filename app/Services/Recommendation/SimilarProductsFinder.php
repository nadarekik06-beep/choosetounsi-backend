<?php

namespace App\Services\Recommendation;

use App\Services\Search\MeiliClient;
use App\Services\Search\SearchUnavailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "You might also like": products similar to a weighted set of seed products.
 *
 * Hybrid score = AI_WEIGHT × vector similarity (multilingual text + CLIP photo vectors
 * stored in the Meilisearch indexes, normalized to 0..1)
 *              + (1 − AI_WEIGHT) × content similarity (same subcategory/category,
 * brand, price). The content part keeps results grounded when embeddings are noisy
 * and covers products whose vectors aren't indexed yet. If Meilisearch is slow or down,
 * content similarity is used alone and the index is skipped for a while.
 */
class SimilarProductsFinder
{
    const AI_WEIGHT     = 0.65;
    const DOWN_FLAG     = 'reco:ai_similar_down';
    const CACHE_MINUTES = 30;

    // Vector similarity: text (multilingual MiniLM) vs main photo (CLIP), when both exist.
    const TEXT_WEIGHT    = 0.60;
    // CLIP cosine between two unrelated product photos is already ~0.5: rescale from there.
    const IMAGE_BASELINE = 0.50;
    const MIN_SIMILARITY = 0.20;

    public function __construct(
        private CandidatePools $pools,
        private InterestProfileService $profiles,
        private MeiliClient $meili,
    ) {}

    /**
     * @param array<int, float> $seeds  product_id => weight (0..1)
     * @return array{scores: array<int, float>, source: string, seeds_of: array<int, int>}
     *         scores: product_id => similarity 0..1; seeds_of: product_id => most similar seed;
     *         source: ai | fallback | none
     */
    public function similarTo(array $seeds, int $limit = 60): array
    {
        if (empty($seeds)) {
            return ['scores' => [], 'source' => 'none', 'seeds_of' => []];
        }

        $content = $this->fallback($seeds, 500);
        $ai      = $this->fromAi($seeds, max($limit * 2, 100));
        if (!$ai || !$ai['scores']) {
            return ['scores' => array_slice($content['scores'], 0, $limit, true), 'source' => 'fallback',
                    'seeds_of' => array_intersect_key($content['seeds_of'], array_slice($content['scores'], 0, $limit, true))];
        }

        $max = max($ai['scores']) ?: 1.0;
        $scores = [];
        foreach (array_keys($ai['scores'] + $content['scores']) as $id) {
            $scores[$id] = round(self::AI_WEIGHT * (($ai['scores'][$id] ?? 0) / $max)
                + (1 - self::AI_WEIGHT) * ($content['scores'][$id] ?? 0), 4);
        }
        arsort($scores);
        $scores = array_slice($scores, 0, $limit, true);

        $seedsOf = [];
        foreach ($scores as $id => $_) {
            $seedsOf[$id] = $ai['seeds_of'][$id] ?? $content['seeds_of'][$id] ?? null;
        }

        return ['scores' => $scores, 'source' => 'ai', 'seeds_of' => $seedsOf];
    }

    /**
     * Vector similarity from the search indexes (Meilisearch "similar documents"):
     * multilingual text vectors of the products, blended with CLIP vectors of their main
     * photo when both have one. Score per candidate = max over seeds of weight × similarity
     * (not an average: a user who viewed shoes and a phone should get both).
     *
     * @return array{scores: array<int, float>, seeds_of: array<int, int>}|null  null when unavailable
     */
    private function fromAi(array $seeds, int $limit): ?array
    {
        if (Cache::has(self::DOWN_FLAG)) {
            return null;
        }
        ksort($seeds);
        $key = 'reco:similar:' . md5(json_encode($seeds) . ":{$limit}");
        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }

        try {
            $photos = DB::table('product_images')->whereIn('product_id', array_keys($seeds))
                ->orderByDesc('is_primary')->orderBy('order')->orderBy('id')
                ->get(['id', 'product_id'])->unique('product_id')->pluck('id', 'product_id');

            $out = ['scores' => [], 'seeds_of' => []];
            foreach ($seeds as $seedId => $weight) {
                $text = $this->similarByVector('products', 'text', $seedId, 'id', $limit);
                $image = isset($photos[$seedId]) ? $this->similarByVector('images', 'clip', $photos[$seedId], 'product_id', $limit) : [];

                foreach (array_keys($text + $image) as $id) {
                    if (isset($seeds[$id])) {
                        continue;
                    }
                    $imageSim = isset($image[$id])
                        ? max(0, min(1, ($image[$id] - self::IMAGE_BASELINE) / (1 - self::IMAGE_BASELINE)))
                        : null;
                    $sim = match (true) {
                        isset($text[$id]) && $imageSim !== null => self::TEXT_WEIGHT * $text[$id] + (1 - self::TEXT_WEIGHT) * $imageSim,
                        isset($text[$id]) => $text[$id],
                        default => $imageSim,
                    };
                    $score = (float) $weight * $sim;
                    if ($score >= self::MIN_SIMILARITY && $score > ($out['scores'][$id] ?? 0)) {
                        $out['scores'][$id] = round($score, 4);
                        $out['seeds_of'][$id] = (int) $seedId;
                    }
                }
            }
            arsort($out['scores']);
            Cache::put($key, $out, now()->addMinutes(self::CACHE_MINUTES));
            return $out;
        } catch (\Throwable $e) {
            Cache::put(self::DOWN_FLAG, true, now()->addMinutes((int) config('recommendations.ai.down_flag_minutes', 2)));
            Log::info('[SimilarProductsFinder] Search index unavailable, using content similarity: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Cosine similarity of the nearest documents to one document, by product.
     * A seed missing from the index (or an embedder that is switched off) gives [].
     *
     * @return array<int, float> product_id => cosine
     */
    private function similarByVector(string $index, string $embedder, int $docId, string $productField, int $limit): array
    {
        try {
            $hits = $this->meili->similar($index, [
                'id' => $docId, 'embedder' => $embedder, 'limit' => min($limit, 200),
                'showRankingScore' => true, 'attributesToRetrieve' => [$productField],
            ])['hits'] ?? [];
        } catch (SearchUnavailable $e) {
            if (in_array($e->status, [400, 404], true)) {
                return [];   // not indexed yet / embedder disabled
            }
            throw $e;
        }

        $out = [];
        foreach ($hits as $hit) {
            // Meilisearch ranks vector hits with (1 + cosine) / 2.
            $out[(int) $hit[$productField]] ??= 2 * (float) $hit['_rankingScore'] - 1;
        }
        return $out;
    }

    private function fallback(array $seeds, int $limit): array
    {
        $pool   = $this->pools->get();
        $brands = $pool['brands'];

        // Seeds may be out of stock now: read their meta straight from the catalog if present,
        // otherwise from the DB so an out-of-stock favourite still yields suggestions.
        $seedMeta = [];
        $missing  = [];
        foreach (array_keys($seeds) as $id) {
            if (isset($pool['products'][$id])) {
                $seedMeta[$id] = $pool['products'][$id];
            } else {
                $missing[] = $id;
            }
        }
        if ($missing) {
            foreach (DB::table('products')->whereIn('id', $missing)->get(['id', 'category_id', 'subcategory_id', 'price']) as $r) {
                $seedMeta[(int) $r->id] = (object) ['id' => (int) $r->id, 'category_id' => (int) $r->category_id,
                    'subcategory_id' => $r->subcategory_id ? (int) $r->subcategory_id : null, 'price' => (float) $r->price];
            }
        }
        if (!$seedMeta) {
            return ['scores' => [], 'source' => 'fallback', 'seeds_of' => []];
        }
        $seedBrands = $brands + ($missing ? $this->profiles->brandMap($missing) : []);

        $scores = $seedOf = [];
        foreach ($pool['products'] as $id => $p) {
            if (isset($seeds[$id])) {
                continue;
            }
            $best = 0.0;
            $bestSeed = null;
            foreach ($seedMeta as $sid => $s) {
                if ($p->subcategory_id && $p->subcategory_id === $s->subcategory_id) {
                    $sim = 0.6;
                } elseif ($p->category_id && $p->category_id === $s->category_id) {
                    $sim = 0.35;
                } else {
                    continue;
                }
                if (isset($brands[$id], $seedBrands[$sid]) && $brands[$id] === $seedBrands[$sid]) {
                    $sim += 0.25;
                }
                if ($p->price > 0 && $s->price > 0) {
                    $z = log($p->price / $s->price) / 0.4;
                    $sim += 0.15 * exp(-0.5 * $z * $z);
                }
                $sim *= $seeds[$sid];
                if ($sim > $best) {
                    [$best, $bestSeed] = [$sim, $sid];
                }
            }
            if ($best > 0) {
                $scores[$id] = round($best, 4);
                $seedOf[$id] = $bestSeed;
            }
        }

        arsort($scores);
        $scores = array_slice($scores, 0, $limit, true);

        return ['scores' => $scores, 'source' => 'fallback', 'seeds_of' => array_intersect_key($seedOf, $scores)];
    }
}

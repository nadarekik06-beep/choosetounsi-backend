<?php

namespace App\Services\Recommendation;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * "You might also like": products similar to a weighted set of seed products.
 *
 * Hybrid score = AI_WEIGHT × AI similarity (MiniLM text + CLIP image vectors from the
 * FAISS search indexes, via the FastAPI /similar endpoint, normalized to 0..1)
 *              + (1 − AI_WEIGHT) × content similarity (same subcategory/category,
 * brand, price). The content part keeps results grounded when embeddings are noisy
 * and covers products added after the last index rebuild. If the AI service is slow
 * or down, content similarity is used alone and the service is skipped for a while.
 */
class SimilarProductsFinder
{
    const AI_WEIGHT     = 0.65;
    const DOWN_FLAG     = 'reco:ai_similar_down';
    const CACHE_MINUTES = 30;

    public function __construct(
        private CandidatePools $pools,
        private InterestProfileService $profiles,
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

    /** @return array{scores: array<int, float>, seeds_of: array<int, int>}|null  null when unavailable */
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
            $res = Http::ai()->timeout((int) config('recommendations.ai.similar_timeout', 2))
                ->withOptions(['connect_timeout' => 1])
                ->acceptJson()
                ->post(rtrim(config('services.ai.url', 'http://localhost:8001'), '/') . '/similar', [
                    'seeds' => (object) array_map('floatval', $seeds),
                    'limit' => min($limit, 200),
                ]);
            if (!$res->successful()) {
                throw new \RuntimeException('HTTP ' . $res->status());
            }

            $out = ['scores' => [], 'seeds_of' => []];
            foreach ($res->json('results', []) as $r) {
                $out['scores'][(int) $r['product_id']]   = (float) $r['score'];
                $out['seeds_of'][(int) $r['product_id']] = (int) $r['seed_id'];
            }
            Cache::put($key, $out, now()->addMinutes(self::CACHE_MINUTES));
            return $out;
        } catch (\Throwable $e) {
            Cache::put(self::DOWN_FLAG, true, now()->addMinutes((int) config('recommendations.ai.down_flag_minutes', 2)));
            Log::info('[SimilarProductsFinder] AI service unavailable, using content similarity: ' . $e->getMessage());
            return null;
        }
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

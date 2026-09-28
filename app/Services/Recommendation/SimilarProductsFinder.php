<?php

namespace App\Services\Recommendation;

use Illuminate\Support\Facades\DB;

/**
 * "You might also like": products similar to a weighted set of seed products.
 *
 * Content-based fallback: same subcategory > same category, plus brand and
 * price proximity. The AI service (MiniLM/CLIP + FAISS) is tried first once
 * wired in; this fallback keeps the section alive when it's down.
 */
class SimilarProductsFinder
{
    public function __construct(
        private CandidatePools $pools,
        private InterestProfileService $profiles,
    ) {}

    /**
     * @param array<int, float> $seeds  product_id => weight (0..1)
     * @return array{scores: array<int, float>, source: string, seeds_of: array<int, int>}
     *         scores: product_id => similarity 0..1; seeds_of: product_id => most similar seed
     */
    public function similarTo(array $seeds, int $limit = 60): array
    {
        if (empty($seeds)) {
            return ['scores' => [], 'source' => 'none', 'seeds_of' => []];
        }
        return $this->fallback($seeds, $limit);
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

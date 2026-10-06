<?php

namespace App\Services\GrowthRadar\Detectors;

use App\Services\GrowthRadar\Benchmarks;
use App\Services\GrowthRadar\Card;
use App\Services\GrowthRadar\SellerContext;
use App\Services\Search\SearchText;
use Illuminate\Support\Facades\DB;

/**
 * Searches in the seller's categories that found nothing (or almost nothing):
 * "Buyers searched 'X' 140 times — you could list it."
 *
 * Search volume is buyer data from the whole platform, so a query is shown only
 * with at least config('growth.privacy.min_events') searches. A missed search
 * without a category is matched to one by the words of category/subcategory
 * names. Queries the seller already covers (all words in one of their product
 * names) are skipped.
 */
class HiddenDemandDetector implements Detector
{
    use BuildsActions;

    private ?array $categoryWords = null;

    public function __construct(private SearchText $text) {}

    public function detect(SellerContext $ctx, Benchmarks $bench): array
    {
        $cfg = config('growth.hidden_demand');
        $cats = array_flip($ctx->categoryIds());
        if (!$cats) return [];

        $rows = DB::table('search_missed_queries')
            ->where('day', '>=', $ctx->today->subDays((int) config('growth.window_days'))->toDateString())
            ->where('results', '<=', $cfg['max_results'])
            ->groupBy('query')
            ->selectRaw('query, SUM(searches) as searches, MAX(category_id) as category_id, MAX(example) as example, MIN(results) as results')
            ->havingRaw('SUM(searches) >= ?', [Benchmarks::floor('min_events')])
            ->orderByDesc('searches')->limit(200)->get();

        $own = array_map(fn ($p) => ' ' . implode(' ', $this->text->tokens($p['name'])) . ' ', $ctx->products);
        $cards = [];
        foreach ($rows as $r) {
            $category = $r->category_id ? (int) $r->category_id : $this->guessCategory($r->query);
            if (!$category || !isset($cats[$category])) continue;
            $tokens = array_filter(explode(' ', $r->query));
            if (!$tokens || $this->covered($tokens, $own)) continue;

            $searches = (int) $r->searches;
            $range = $bench->priceRange($category, null);
            $sellerPrices = array_column(array_filter($ctx->products, fn ($p) => $p['category_id'] === $category), 'price');
            $price = $range['median'] ?? ($sellerPrices ? array_sum($sellerPrices) / count($sellerPrices) : null);
            [$lo, $hi] = $cfg['search_to_order'];

            $label = trim((string) ($r->example ?: $r->query));
            $card = new Card('hidden_demand', 'demand:' . mb_substr($r->query, 0, 100), null,
                $searches >= 100 ? 'medium' : 'low',
                ['query' => $label, 'searches' => $searches, 'days' => (int) config('growth.window_days')],
                ['numbers' => array_values(array_filter([
                    ['key' => 'searches_30d', 'value' => $searches],
                    ['key' => 'results_found', 'value' => (int) $r->results],
                    $price ? ['key' => 'typical_price', 'value' => round($price), 'unit' => 'dt'] : null,
                 ]))],
                ['kind' => 'listing', 'query' => $label, 'category_id' => $category],
                null,
                ['searches' => 'platform', 'price' => $range ? $range['scope'] : ($price ? 'own' : 'none')],
            );
            $cards[] = $price ? $card->impact($searches * $lo * $price, $searches * $hi * $price) : $card->impact(null, null);
            if (count($cards) >= $cfg['max_cards']) break;
        }
        return $cards;
    }

    /** All query words appear in one of the seller's product names. */
    private function covered(array $tokens, array $ownNames): bool
    {
        foreach ($ownNames as $name) {
            $all = true;
            foreach ($tokens as $t) {
                if (!str_contains($name, " $t ")) { $all = false; break; }
            }
            if ($all) return true;
        }
        return false;
    }

    /** Category whose (sub)category names share the most words with the query. */
    public function guessCategory(string $query): ?int
    {
        $words = $this->categoryWords();
        $best = null; $bestHits = 0; $hits = [];
        foreach (array_filter(explode(' ', $query)) as $t) {
            foreach ($words[$t] ?? [] as $cat => $_) {
                $hits[$cat] = ($hits[$cat] ?? 0) + 1;
                if ($hits[$cat] > $bestHits) { $bestHits = $hits[$cat]; $best = $cat; }
            }
        }
        return $best;
    }

    /** token => [category_id => true] from category and subcategory names in every language. */
    private function categoryWords(): array
    {
        if ($this->categoryWords !== null) return $this->categoryWords;
        $out = [];
        $add = function (int $cat, ?string $text) use (&$out) {
            foreach ($this->text->tokens($text) as $t) {
                if (mb_strlen($t) >= 3) $out[$t][$cat] = true;
            }
        };
        foreach (DB::table('categories')->where('is_active', true)->get(['id', 'name', 'name_fr', 'name_ar']) as $c) {
            foreach ([$c->name, $c->name_fr, $c->name_ar] as $n) $add((int) $c->id, $n);
        }
        foreach (DB::table('subcategories')->where('is_active', true)->get(['category_id', 'name', 'name_fr', 'name_ar', 'slug']) as $s) {
            foreach ([$s->name, $s->name_fr, $s->name_ar, str_replace('-', ' ', (string) $s->slug)] as $n) $add((int) $s->category_id, $n);
        }
        return $this->categoryWords = $out;
    }
}

<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\DB;

/**
 * The search bar: deterministic keyword search on MySQL (product_search_index).
 *
 *  1. The query is cut into search tokens (SearchText: accents, plurals, stop words, Arabic)
 *     and grouped into concepts; each concept also carries its synonyms (synonyms.txt), which
 *     only count inside the categories the synonym line is limited to.
 *  2. Each product is scored per concept, on where the word is found:
 *         name, exact word 60 · category 50 · name, word start 35 · brand/attributes 20 · description 8
 *     a synonym counts 40% (best of word/synonyms per field, fields added up: an "ensemble"
 *     named product in the ENSEMBLES category scores 110, a "Dress" in the Robe category 74),
 *     the whole query being a product's full name adds 40.
 *  3. "direct" = every concept found in the name, category or attributes (≥ 20 each);
 *     anything weaker (description only, some words only) is "weak" and never a best result.
 *  4. Fewer than 3 direct results → each unknown word is corrected against the catalog
 *     vocabulary (DidYouMean) and the corrected query is kept when it finds more.
 *  5. Ties (a few points at most): in stock, rating, recent sales, real photo.
 *
 * Zero/low-result queries are logged for the admin "Missed searches" page.
 */
class ProductSearch
{
    const NAME = 60;
    const NAME_PREFIX = 35;
    const CATEGORY = 50;
    const CATEGORY_PREFIX = 30;
    const EXTRA = 20;
    const DESCRIPTION = 8;
    const SYNONYM = 0.4;
    const FULL_NAME_BONUS = 40;
    /** Every concept must score at least this for a "direct" (best) result: attributes or better. */
    const MIN_DIRECT = 20;
    const MAX_TOKENS = 8;
    const MAX_CANDIDATES = 2000;

    public function __construct(
        private SearchText $text,
        private Synonyms $synonyms,
        private DidYouMean $didYouMean,
        private BusinessSignals $signals,
        private MissedQueries $missed,
    ) {}

    /**
     * @return array{
     *   direct: array<int, float>, weak: array<int, float>,   product id => score, best first
     *   did_you_mean: ?string, normalized: string
     * }
     */
    public function search(string $query, ?int $categoryId = null, bool $log = true, bool $correct = true): array
    {
        $words = array_slice($this->text->words($query), 0, self::MAX_TOKENS);
        $tokens = array_map([$this->text, 'stem'], $words);
        $result = ['direct' => [], 'weak' => [], 'did_you_mean' => null, 'normalized' => implode(' ', $tokens)];
        if (!$tokens) {
            return $result;
        }

        [$direct, $weak] = $this->keyword($tokens, $categoryId);

        // Few real matches: maybe a typo ("ensembel", "chaussur").
        if ($correct && count($direct) < (int) config('search.low_results', 3)) {
            $fixes = $this->didYouMean->corrections($tokens);
            if ($fixes) {
                [$fixedDirect, $fixedWeak] = $this->keyword(array_replace($tokens, $fixes), $categoryId);
                if (count($fixedDirect) > count($direct) || (!$direct && count($fixedWeak) > count($weak))) {
                    [$direct, $weak] = [$fixedDirect, $fixedWeak];
                    $result['did_you_mean'] = implode(' ', array_replace($words, $fixes));
                }
            }
        }

        [$result['direct'], $result['weak']] = $this->rerank($direct, $weak);

        if ($log) {
            $this->missed->record($result['normalized'], $query, count($result['direct']));
        }
        return $result;
    }

    /**
     * Relevance of the real matches only (no typo correction, nothing logged): ad targeting,
     * promo flyers and the chatbot. Weak matches never count here.
     *
     * @return array<int, float> product_id => 0.5..1, best first
     */
    public function scores(string $query, int $limit = 100): array
    {
        $result = $this->search($query, null, false, false);
        $out = [];
        foreach (array_slice($result['direct'], 0, $limit, true) as $id => $score) {
            $out[$id] = round(0.5 + 0.5 * min(1, $score / 120), 4);
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param string[] $tokens
     * @return array{0: array<int, float>, 1: array<int, float>} direct and weak matches, unsorted
     */
    private function keyword(array $tokens, ?int $categoryId): array
    {
        $concepts = $this->concepts($tokens);
        $query = implode(' ', $tokens);

        // Candidates: any word of any concept (or of its synonyms) starting a word anywhere.
        $starts = [];
        foreach ($concepts as $c) {
            foreach ($c['phrases'] as $p) {
                $starts[explode(' ', $p['phrase'])[0]] = true;
            }
        }
        $rows = DB::table('product_search_index as s')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->where('p.is_approved', 1)->where('p.is_active', 1)->whereNull('p.deleted_at')
            ->when($categoryId, fn ($q) => $q->where('s.category_id', $categoryId))
            ->where(function ($q) use ($starts) {
                foreach (array_keys($starts) as $w) {
                    $q->orWhere('s.all_text', 'LIKE', '% ' . addcslashes($w, '%_\\') . '%');
                }
            })
            ->limit(self::MAX_CANDIDATES)
            ->get(['s.product_id', 's.category_id', 's.names', 's.category', 's.extra', 's.description']);

        $direct = $weak = [];
        foreach ($rows as $row) {
            $total = 0.0;
            $strong = true;
            foreach ($concepts as $c) {
                $score = $this->conceptScore($row, $c);
                $total += $score;
                $strong = $strong && $score >= self::MIN_DIRECT;
            }
            if ($total <= 0) {
                continue;
            }
            $score = $total / count($concepts);
            if (str_contains($row->names, "| $query |")) {
                $score += self::FULL_NAME_BONUS;
            }
            if ($strong) {
                $direct[(int) $row->product_id] = $score;
            } else {
                $weak[(int) $row->product_id] = $score;
            }
        }
        return [$direct, $weak];
    }

    /**
     * The query as concepts: a word, or a phrase the synonym file knows ("sac main", "robe soiree"),
     * each with its alternatives.
     *
     * @return array<int, array{phrases: array<int, array{phrase: string, weight: float, categories: ?int[], prefix: bool}>}>
     */
    private function concepts(array $tokens): array
    {
        $lookup = $this->synonyms->lookup();
        $concepts = [];
        for ($i = 0; $i < count($tokens);) {
            for ($n = min(3, count($tokens) - $i); $n >= 1; $n--) {
                $phrase = implode(' ', array_slice($tokens, $i, $n));
                if ($n === 1 || isset($lookup[$phrase])) {
                    break;
                }
            }
            $phrases = [['phrase' => $phrase, 'weight' => 1.0, 'categories' => null, 'prefix' => true]];
            foreach ($lookup[$phrase] ?? [] as $alt) {
                $phrases[] = ['phrase' => $alt['phrase'], 'weight' => self::SYNONYM, 'categories' => $alt['categories'], 'prefix' => false];
            }
            $concepts[] = ['phrases' => $phrases];
            $i += $n;
        }
        return $concepts;
    }

    /**
     * The concept's score in this product: per field, the best of the word itself and its
     * synonyms; then the fields added up ("Dress" named + "Robe" category beats "robe" alone).
     */
    private function conceptScore(object $row, array $concept): float
    {
        $fields = [
            [$row->names, self::NAME, self::NAME_PREFIX],
            [$row->category, self::CATEGORY, self::CATEGORY_PREFIX],
            [$row->extra, self::EXTRA, 0],
            [$row->description, self::DESCRIPTION, 0],
        ];
        $best = array_fill(0, count($fields), 0.0);
        foreach ($concept['phrases'] as $p) {
            if ($p['categories'] !== null && !in_array((int) $row->category_id, $p['categories'], true)) {
                continue;   // synonym limited to other categories ("set" outside clothing)
            }
            foreach ($fields as $i => [$text, $exact, $prefix]) {
                $best[$i] = max($best[$i], $this->fieldScore($text, $p, $exact, $prefix) * $p['weight']);
            }
        }
        return array_sum($best);
    }

    /**
     * Whole word(s) → $exact. The start of a word (people stop typing early: "chaussur",
     * "ensembl") → $prefix, at most 2 letters short so "casque" doesn't match "casquette".
     */
    private function fieldScore(string $field, array $p, float $exact, float $prefix): float
    {
        if ($field === '') {
            return 0.0;
        }
        if (str_contains($field, ' ' . $p['phrase'] . ' ')) {
            return $exact;
        }
        if ($prefix > 0 && $p['prefix'] && mb_strlen($p['phrase']) >= 4
            && preg_match('/ ' . preg_quote($p['phrase'], '/') . '[^\s|]{1,2} /u', $field)) {
            return $prefix;
        }
        return 0.0;
    }

    /** Relevance first; in stock, rating, sales and a real photo only reorder close scores. */
    private function rerank(array $direct, array $weak): array
    {
        $ids = array_keys($direct + $weak);
        if (!$ids) {
            return [[], []];
        }
        $signals = $this->signals->for($ids);
        $maxSold = max(array_map(fn ($s) => $s['sold'], $signals) ?: [0]);
        $photos = DB::table('product_images')->whereIn('product_id', $ids)
            ->where('image_path', 'NOT LIKE', 'products/demo/%')->distinct()->pluck('product_id')->flip();

        $sort = function (array $hits) use ($signals, $maxSold, $photos) {
            foreach ($hits as $id => &$score) {
                $boost = isset($signals[$id]) ? $this->signals->boost($signals[$id], $maxSold) * 20 : 0;
                $score = round($score + $boost + (isset($photos[$id]) ? 1 : 0), 3);
            }
            unset($score);
            arsort($hits);
            return $hits;
        };
        return [$sort($direct), $sort($weak)];
    }
}

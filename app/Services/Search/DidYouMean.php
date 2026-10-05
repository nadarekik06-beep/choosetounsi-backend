<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Spelling correction against the catalog's own vocabulary: every search token of the live
 * products (names, categories in EN/FR/AR, brands and attributes) plus the synonym file.
 * Only words that are NOT in the vocabulary are corrected, so a valid word is never rewritten.
 *
 * "ensembel" → "ensemble", "ensemle" → "ensemble", "chaussur" → "chaussure", "jupee" → "jupe".
 * Allowed distance: 1 edit up to 5 letters, 2 from 6 letters (a swap of two letters is 1 edit).
 * Cached; SearchIndexer clears it whenever the index changes.
 */
class DidYouMean
{
    const CACHE_KEY = 'search:vocabulary:v2';

    public function __construct(private SearchText $text, private Synonyms $synonyms) {}

    /**
     * @param string[] $tokens query tokens (SearchText::tokens)
     * @return array<int, string> position => corrected token, only for the words that changed
     */
    public function corrections(array $tokens): array
    {
        $vocab = $this->vocabulary();
        if (!$vocab) {
            return [];
        }

        $out = [];
        foreach ($tokens as $i => $word) {
            $len = mb_strlen($word);
            if (isset($vocab[$word]) || $len < 4 || preg_match('/\d/u', $word)) {
                continue;
            }
            $max = $len <= 5 ? 1 : 2;
            $best = null;
            $bestScore = [PHP_INT_MAX, 0];
            foreach ($vocab as $candidate => $freq) {
                $candidate = (string) $candidate;
                if (abs(mb_strlen($candidate) - $len) > $max) {
                    continue;
                }
                $d = self::distance($word, $candidate);
                // Closest first, then the more common word.
                if ($d <= $max && ($d < $bestScore[0] || ($d === $bestScore[0] && $freq > $bestScore[1]))) {
                    $best = $candidate;
                    $bestScore = [$d, $freq];
                }
            }
            if ($best !== null) {
                $out[$i] = $best;
            }
        }
        return $out;
    }

    /** @return array<string, int> search token => how many live products / synonym entries use it */
    public function vocabulary(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addHours(6), function () {
            $freq = [];
            DB::table('product_search_index as s')
                ->join('products as p', 'p.id', '=', 's.product_id')
                ->where('p.is_approved', 1)->where('p.is_active', 1)->whereNull('p.deleted_at')
                ->select('s.product_id', 's.names', 's.category', 's.extra')->orderBy('s.product_id')
                ->chunk(1000, function ($rows) use (&$freq) {
                    foreach ($rows as $r) {
                        $words = preg_split('/[\s|]+/u', "$r->names $r->category $r->extra", -1, PREG_SPLIT_NO_EMPTY);
                        foreach (array_unique($words) as $w) {
                            if (mb_strlen($w) >= 3) {
                                $freq[$w] = ($freq[$w] ?? 0) + 1;
                            }
                        }
                    }
                });
            foreach ($this->synonyms->words() as $w) {
                $w = $this->text->stem($w);
                $freq[$w] = ($freq[$w] ?? 0) + 1;
            }
            return $freq;
        });
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Edit distance on characters (PHP's levenshtein() counts bytes, wrong for Arabic), where
     * swapping two neighbouring letters counts as one edit ("ensembel" → "ensemble").
     */
    public static function distance(string $a, string $b): int
    {
        if ($a === $b) {
            return 0;
        }
        $a = mb_str_split($a);
        $b = mb_str_split($b);
        $n = count($a);
        $m = count($b);
        $d = [];
        for ($i = 0; $i <= $n; $i++) $d[$i][0] = $i;
        for ($j = 0; $j <= $m; $j++) $d[0][$j] = $j;
        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);
                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
                }
            }
        }
        return $d[$n][$m];
    }
}

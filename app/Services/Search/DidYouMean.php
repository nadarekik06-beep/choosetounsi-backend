<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Did you mean …?" for queries the search engine's own typo tolerance couldn't rescue
 * (3+ typos, or a word Meilisearch splits differently). Only words that are NOT in the
 * catalog vocabulary are corrected, so a valid word is never rewritten into another one.
 */
class DidYouMean
{
    const CACHE_KEY = 'search:vocabulary';

    public function __construct(private QueryNormalizer $normalizer, private Synonyms $synonyms) {}

    public function suggest(string $normalizedQuery): ?string
    {
        $vocab = $this->vocabulary();
        if (!$vocab || $normalizedQuery === '') {
            return null;
        }

        $changed = false;
        $words = explode(' ', $normalizedQuery);
        foreach ($words as $i => $word) {
            $len = mb_strlen($word);
            if (isset($vocab[$word]) || $len < 4 || preg_match('/\d/u', $word)) {
                continue;
            }
            $maxDistance = $len >= 7 ? 2 : 1;
            $best = null;
            $bestScore = [PHP_INT_MAX, 0];
            foreach ($vocab as $candidate => $freq) {
                if (abs(mb_strlen($candidate) - $len) > $maxDistance) {
                    continue;
                }
                $d = self::distance($word, $candidate);
                // Closest first, then the more frequent word.
                if ($d <= $maxDistance && ($d < $bestScore[0] || ($d === $bestScore[0] && $freq > $bestScore[1]))) {
                    $best = $candidate;
                    $bestScore = [$d, $freq];
                }
            }
            if ($best !== null) {
                $words[$i] = $best;
                $changed = true;
            }
        }

        return $changed ? implode(' ', $words) : null;
    }

    /** @return array<string, int> normalized word => how many live products/synonym groups use it */
    public function vocabulary(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addHours(6), function () {
            $freq = [];
            $add = function (?string $text) use (&$freq) {
                foreach ($this->normalizer->words($text) as $w) {
                    if (mb_strlen($w) >= 3) {
                        $freq[$w] = ($freq[$w] ?? 0) + 1;
                    }
                }
            };
            DB::table('products')->where('is_approved', 1)->where('is_active', 1)->whereNull('deleted_at')
                ->select('id', 'name', 'translations')->orderBy('id')
                ->chunk(1000, function ($rows) use ($add) {
                    foreach ($rows as $r) {
                        $add($r->name);
                        foreach ((array) json_decode((string) $r->translations, true) as $t) {
                            $add($t['name'] ?? null);
                        }
                    }
                });
            foreach (['categories', 'subcategories'] as $table) {
                foreach (DB::table($table)->get(['name', 'name_fr', 'name_ar']) as $c) {
                    $add("$c->name $c->name_fr $c->name_ar");
                }
            }
            foreach ($this->synonyms->words() as $w) {
                $freq[$w] = ($freq[$w] ?? 0) + 1;
            }
            return $freq;
        });
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** Levenshtein on characters (PHP's levenshtein() counts bytes, wrong for Arabic). */
    public static function distance(string $a, string $b): int
    {
        if ($a === $b) {
            return 0;
        }
        $a = mb_str_split($a);
        $b = mb_str_split($b);
        $prev = range(0, count($b));
        foreach ($a as $i => $ca) {
            $cur = [$i + 1];
            foreach ($b as $j => $cb) {
                $cur[] = min($prev[$j + 1] + 1, $cur[$j] + 1, $prev[$j] + ($ca === $cb ? 0 : 1));
            }
            $prev = $cur;
        }
        return $prev[count($b)];
    }
}

<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\DB;

/**
 * Basic search straight on MySQL, used only while Meilisearch is down. No typo tolerance;
 * it does expand words with the synonym file and look in the FR/AR/EN translations, and the
 * utf8mb4_unicode_ci collation already ignores case and accents.
 */
class MysqlFallbackSearch
{
    public function __construct(private QueryNormalizer $normalizer, private Synonyms $synonyms) {}

    /** @return array<int, float> product_id => score (0..1), best first */
    public function search(string $query, ?int $categoryId, int $limit = 100): array
    {
        $words = array_values(array_filter($this->normalizer->words($query), fn ($w) => mb_strlen($w) >= 2));
        if (!$words) {
            return [];
        }

        $synonyms = $this->synonyms->forMeilisearch();
        $groups = [];   // one group per query word: the word or any of its synonyms
        foreach ($words as $w) {
            $groups[] = array_slice(array_values(array_unique(array_merge([$w], $synonyms[$w] ?? []))), 0, 12);
        }

        $score = [];
        $bindings = [];
        foreach ($groups as $terms) {
            $parts = [];
            foreach ($terms as $t) {
                $parts[] = '(p.name LIKE ? OR p.translations LIKE ?)';
                array_push($bindings, "%$t%", "%$t%");   // translations JSON is stored unescaped
            }
            $score[] = 'CASE WHEN ' . implode(' OR ', $parts) . ' THEN 1 ELSE 0 END';
        }

        $rows = DB::table('products as p')
            ->where('p.is_approved', 1)->where('p.is_active', 1)->whereNull('p.deleted_at')
            ->when($categoryId, fn ($q) => $q->where('p.category_id', $categoryId))
            ->selectRaw('p.id, (' . implode(' + ', $score) . ') AS matched, p.views', $bindings)
            ->havingRaw('matched > 0')
            ->orderByDesc('matched')->orderByDesc('p.views')
            ->limit($limit)->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->id] = round($r->matched / count($groups), 4);
        }
        return $out;
    }
}

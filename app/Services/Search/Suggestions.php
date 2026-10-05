<?php

namespace App\Services\Search;

use App\Support\Localization;
use Illuminate\Support\Facades\DB;

/**
 * Search-bar autocomplete: names of live products containing every word typed so far, the
 * last one as a word start ("ensemble gr" → "Ensemble gris Ralph Lauren"), in the storefront
 * language, most viewed first. Same tokens as the search itself (accents, plurals, Arabic).
 */
class Suggestions
{
    public function __construct(private SearchText $text) {}

    /** @return string[] */
    public function for(string $typed, int $limit = 8): array
    {
        $tokens = array_slice($this->text->words($typed), 0, 6);
        if (!$tokens || mb_strlen(implode('', $tokens)) < 2) {
            return [];
        }
        $last = array_pop($tokens);
        $tokens = array_map([$this->text, 'stem'], $tokens);

        $rows = DB::table('product_search_index as s')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->where('p.is_approved', 1)->where('p.is_active', 1)->whereNull('p.deleted_at')
            ->where('s.names', 'LIKE', '% ' . addcslashes($last, '%_\\') . '%')
            ->when($tokens, function ($q) use ($tokens) {
                foreach ($tokens as $t) {
                    $q->where('s.names', 'LIKE', '% ' . addcslashes($t, '%_\\') . ' %');
                }
            })
            ->orderByDesc('p.views')->limit($limit * 3)
            ->get(['p.id', 'p.name', 'p.translations']);

        $out = [];
        foreach ($rows as $row) {
            $name = trim((string) Localization::productRow($row, ['name'])->name);
            if ($name !== '') {
                $out[mb_strtolower($name)] ??= $name;
            }
        }
        return array_slice(array_values($out), 0, $limit);
    }
}

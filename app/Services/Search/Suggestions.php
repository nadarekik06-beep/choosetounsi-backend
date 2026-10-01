<?php

namespace App\Services\Search;

use App\Support\Localization;
use Illuminate\Support\Facades\DB;

/**
 * Search-bar autocomplete: product names matching what's typed so far (prefix + typo
 * tolerance + synonyms, so "sabb" already proposes sneakers), in the storefront language.
 * Falls back to a MySQL prefix match when Meilisearch is down.
 */
class Suggestions
{
    public function __construct(private MeiliClient $meili, private QueryNormalizer $normalizer) {}

    /** @return string[] */
    public function for(string $typed, int $limit = 8): array
    {
        $q = $this->normalizer->normalize($typed);
        if (mb_strlen($q) < 2) {
            return [];
        }

        try {
            $hits = $this->meili->search('products', [
                'q' => $q, 'limit' => $limit * 2,
                'attributesToRetrieve' => ['label', 'label_fr', 'label_ar'],
            ], 1)['hits'] ?? [];
        } catch (SearchUnavailable) {
            return $this->fallback($typed, $limit);
        }

        $field = ['fr' => 'label_fr', 'ar' => 'label_ar'][Localization::locale()] ?? 'label';
        $out = [];
        foreach ($hits as $hit) {
            $name = trim((string) (($hit[$field] ?? null) ?: $hit['label']));
            $out[mb_strtolower($name)] ??= $name;
        }
        return array_slice(array_values($out), 0, $limit);
    }

    private function fallback(string $typed, int $limit): array
    {
        return DB::table('products')
            ->where('is_approved', 1)->where('is_active', 1)->whereNull('deleted_at')
            ->where('name', 'LIKE', addcslashes(trim($typed), '%_\\') . '%')
            ->orderByDesc('views')->limit($limit)
            ->pluck('name')->unique()->values()->all();
    }
}

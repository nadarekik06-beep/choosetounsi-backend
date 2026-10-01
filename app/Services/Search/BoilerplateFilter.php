<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Strips template text that many product descriptions share ("Vous cherchez un produit qui
 * allie qualité et fiabilité dans la catégorie…", "Demo product for the ChooseTounsi…").
 *
 * A 4-word sequence that appears in the descriptions of many different products is template,
 * not information. Words covered by such sequences are dropped; what remains (the product
 * name the template was filled with, real details) is kept. Learned from the catalog itself,
 * so a seller's own copy-pasted template is caught too.
 */
class BoilerplateFilter
{
    const CACHE_KEY = 'search:boilerplate_shingles';
    const N = 4;

    public function __construct(private QueryNormalizer $normalizer) {}

    /** Normalized description with template words removed. */
    public function clean(?string $description): string
    {
        $words = $this->normalizer->words($description);
        if (count($words) < self::N) {
            return implode(' ', $words);
        }

        $shingles = $this->shingles();
        $template = array_fill(0, count($words), false);
        for ($i = 0; $i + self::N <= count($words); $i++) {
            if (isset($shingles[implode(' ', array_slice($words, $i, self::N))])) {
                for ($j = $i; $j < $i + self::N; $j++) {
                    $template[$j] = true;
                }
            }
        }

        return implode(' ', array_values(array_filter($words, fn ($w, $i) => !$template[$i], ARRAY_FILTER_USE_BOTH)));
    }

    /** @return array<string, true> frequent shingles, learned once and cached (rebuilt by search:reindex) */
    public function shingles(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => $this->learn());
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, true> */
    public function learn(): array
    {
        $df = [];
        $docs = 0;
        DB::table('products')->whereNull('deleted_at')->whereNotNull('description')
            ->select('id', 'description')->orderBy('id')
            ->chunk(500, function ($rows) use (&$df, &$docs) {
                foreach ($rows as $row) {
                    $docs++;
                    $words = $this->normalizer->words($row->description);
                    $seen = [];
                    for ($i = 0; $i + self::N <= count($words); $i++) {
                        $seen[implode(' ', array_slice($words, $i, self::N))] = true;
                    }
                    foreach ($seen as $s => $_) {
                        $df[$s] = ($df[$s] ?? 0) + 1;
                    }
                }
            });

        // "Many products": at least 5, and at least 3% of a large catalog.
        $threshold = max(5, (int) ceil($docs * 0.03));
        return array_map(fn () => true, array_filter($df, fn ($n) => $n >= $threshold));
    }
}

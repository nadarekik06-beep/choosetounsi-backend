<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * resources/search/synonyms.txt: one group of equivalent words per line, comma separated,
 * optionally limited to some categories with "@ slug, slug" at the end of the line:
 *
 *   set, ensemble, tenue, طقم  @ fashion-clothing, sports-outdoors
 *
 * A scoped group only applies to products of those categories, so "ensemble" finds clothing
 * sets but never a "Ceramic Plate Set". Entries are normalized like queries and indexed text,
 * so the file can be written naturally (accents, Arabic article, plurals don't matter).
 */
class Synonyms
{
    const CACHE_KEY = 'search:synonyms:v1';

    public function __construct(private QueryNormalizer $normalizer, private SearchText $text) {}

    public function path(): string
    {
        return config('search.synonyms_path');
    }

    /** @return array<int, string[]> normalized groups, duplicates and 1-entry groups removed (scopes ignored) */
    public function groups(): array
    {
        return array_column($this->parse(), 'terms');
    }

    /** @return array<string, string[]> Meilisearch "synonyms" setting */
    public function forMeilisearch(): array
    {
        $map = [];
        foreach ($this->groups() as $terms) {
            foreach ($terms as $term) {
                $map[$term] = array_values(array_unique(array_merge($map[$term] ?? [], array_diff($terms, [$term]))));
            }
        }
        ksort($map);
        return $map;
    }

    /** @return string[] every single-word synonym term, normalized, not stemmed (feeds "did you mean") */
    public function words(): array
    {
        $words = [];
        foreach ($this->groups() as $terms) {
            foreach ($terms as $term) {
                foreach ($this->text->words($term) as $w) {
                    $words[$w] = true;
                }
            }
        }
        return array_keys($words);
    }

    /**
     * Search-token phrase => its alternatives, each with the category ids it applies to
     * (null = everywhere). "sac a main" is stored as "sac main", like the indexed text.
     *
     * @return array<string, array<int, array{phrase: string, categories: ?int[]}>>
     */
    public function lookup(): array
    {
        return Cache::remember(self::CACHE_KEY . ':' . (is_file($this->path()) ? filemtime($this->path()) : 0), now()->addDay(), function () {
            $slugs = DB::table('categories')->pluck('id', 'slug')->all();
            $map = [];
            foreach ($this->parse() as $group) {
                $categories = $group['scope'] === null ? null
                    : array_values(array_filter(array_map(fn ($s) => $slugs[$s] ?? null, $group['scope'])));
                $phrases = array_values(array_unique(array_filter(array_map(
                    fn ($t) => implode(' ', $this->text->tokens($t)), $group['terms']))));
                foreach ($phrases as $phrase) {
                    foreach ($phrases as $other) {
                        if ($other !== $phrase) {
                            $map[$phrase][$other] = ['phrase' => $other, 'categories' => $categories];
                        }
                    }
                }
            }
            return array_map('array_values', $map);
        });
    }

    /** @return array<int, array{terms: string[], scope: ?string[]}> */
    private function parse(): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return [];
        }

        $groups = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $scope = null;
            if (str_contains($line, '@')) {
                [$line, $slugs] = explode('@', $line, 2);
                $scope = array_values(array_filter(array_map('trim', explode(',', $slugs))));
            }
            $terms = array_values(array_unique(array_filter(array_map(
                fn ($t) => $this->normalizer->normalize($t),
                explode(',', $line)
            ))));
            if (count($terms) > 1) {
                $groups[] = ['terms' => $terms, 'scope' => $scope ?: null];
            }
        }
        return $groups;
    }
}

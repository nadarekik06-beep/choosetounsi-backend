<?php

namespace App\Services\Search;

/**
 * resources/search/synonyms.txt → Meilisearch synonyms (every word of a group points to the others).
 * Entries are normalized like queries and indexed text, so the file can be written naturally.
 */
class Synonyms
{
    public function __construct(private QueryNormalizer $normalizer) {}

    public function path(): string
    {
        return config('search.synonyms_path');
    }

    /** @return array<int, string[]> normalized groups, duplicates and 1-entry groups removed */
    public function groups(): array
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
            $terms = array_values(array_unique(array_filter(array_map(
                fn ($t) => $this->normalizer->normalize($t),
                explode(',', $line)
            ))));
            if (count($terms) > 1) {
                $groups[] = $terms;
            }
        }
        return $groups;
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

    /** @return string[] every single-word synonym term (feeds "did you mean") */
    public function words(): array
    {
        $words = [];
        foreach ($this->groups() as $terms) {
            foreach ($terms as $term) {
                foreach (explode(' ', $term) as $w) {
                    $words[$w] = true;
                }
            }
        }
        return array_keys($words);
    }
}

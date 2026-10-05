<?php

namespace App\Services\Search;

/**
 * Search tokens: what the product index stores and what queries are cut into. Both sides go
 * through the same steps, so they always meet:
 *
 *  1. QueryNormalizer (lowercase, accents folded, Arabic letters unified, "ال" dropped)
 *  2. "t shirt" / "tee shirt" / "t-shirt" → "tshirt"
 *  3. stop words (de, la, pour, the, for, في…) and single letters (l', d') dropped
 *  4. plurals folded: robes → robe, ensembles → ensemble, bijoux → bijou, dresses → dress
 */
class SearchText
{
    private const STOP_WORDS = [
        // FR
        'de', 'du', 'des', 'la', 'le', 'les', 'un', 'une', 'et', 'ou', 'en', 'au', 'aux', 'pour',
        'avec', 'sans', 'sur', 'par', 'dans', 'ce', 'ces', 'mon', 'ma', 'mes', 'son', 'sa', 'ses',
        // EN
        'the', 'for', 'and', 'of', 'with', 'in', 'on', 'to', 'an', 'my', 'by', 'from',
        // AR
        'في', 'من', 'على', 'مع', 'الى', 'عن', 'او', 'و', 'لل',
    ];

    public function __construct(private QueryNormalizer $normalizer) {}

    /** @return string[] index/query tokens (normalized, no stop words, plurals folded) */
    public function tokens(?string $text): array
    {
        return array_map([$this, 'stem'], $this->words($text));
    }

    /** Tokens joined with single spaces and padded (" a b "), ready for word matching. */
    public function padded(?string $text): string
    {
        $tokens = $this->tokens($text);
        return $tokens ? ' ' . implode(' ', $tokens) . ' ' : '';
    }

    /** @return string[] normalized words, stop words removed, NOT stemmed (what people typed). */
    public function words(?string $text): array
    {
        $normalized = $this->normalizer->normalize($text);
        if ($normalized === '') {
            return [];
        }
        $normalized = preg_replace('/(^| )(t|tee) shirts?( |$)/u', '$1tshirt$3', $normalized);

        $stop = array_flip(self::STOP_WORDS);
        return array_values(array_filter(
            explode(' ', $normalized),
            fn ($w) => !isset($stop[$w]) && !preg_match('/^[a-z]$/', $w)
        ));
    }

    /** Light plural folding for Latin words; Arabic words are left as they are. */
    public function stem(string $word): string
    {
        if (mb_strlen($word) < 4 || !preg_match('/^[a-z]+$/', $word)) {
            return $word;
        }
        if (str_ends_with($word, 'sses')) {
            return substr($word, 0, -2);                      // dresses → dress
        }
        if (preg_match('/(eau|au|eu|ou)x$/', $word)) {
            return substr($word, 0, -1);                      // bijoux → bijou, chapeaux → chapeau
        }
        if (str_ends_with($word, 's') && !preg_match('/(ss|us|is)$/', $word)) {
            return substr($word, 0, -1);                      // robes → robe, sacs → sac
        }
        return $word;
    }
}

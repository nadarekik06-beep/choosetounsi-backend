<?php

namespace App\Services\Search;

/**
 * One normalization for everything that is searched: queries, indexed product text and
 * synonym entries. Both sides go through the same function, so "Soirée", "soiree",
 * "أحذية" and "احذيه" meet in the middle.
 *
 *  - lowercase, Latin accents/ligatures folded (é→e, œ→oe)
 *  - Arabic: diacritics and tatweel removed, أ/إ/آ/ٱ→ا, ى/ی→ي, ة→ه, ؤ→و, ئ→ي,
 *    Arabic-Indic digits → 0-9, leading article "ال" (also وال/بال/فال/كال/لل) dropped
 *  - punctuation becomes a space; letters+digits stay together ("3sal", "ps5")
 */
class QueryNormalizer
{
    private const LATIN = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
        'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss',
        '’' => "'", '‘' => "'", 'ʼ' => "'",
    ];

    private const ARABIC = [
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
        'ى' => 'ي', 'ی' => 'ي', 'ئ' => 'ي', 'ؤ' => 'و', 'ة' => 'ه',
        'ک' => 'ك', 'ڨ' => 'ق', 'ڤ' => 'ف', 'پ' => 'ب', 'چ' => 'ج',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    public function normalize(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $text = mb_strtolower(strip_tags($text), 'UTF-8');
        $text = strtr($text, self::LATIN);
        // Arabic diacritics (harakat, shadda, sukun, superscript alef) and tatweel.
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);
        $text = strtr($text, self::ARABIC);
        // Anything that isn't a letter or digit separates words.
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        $words = [];
        foreach (preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $words[] = $this->stripArabicArticle($word);
        }

        return implode(' ', $words);
    }

    /** @return string[] normalized words */
    public function words(?string $text): array
    {
        $normalized = $this->normalize($text);
        return $normalized === '' ? [] : explode(' ', $normalized);
    }

    private function stripArabicArticle(string $word): string
    {
        if (!preg_match('/^\p{Arabic}/u', $word)) {
            return $word;
        }
        foreach (['وال', 'بال', 'فال', 'كال', 'لل', 'ال'] as $prefix) {
            if (str_starts_with($word, $prefix) && mb_strlen($word) - mb_strlen($prefix) >= 3) {
                return mb_substr($word, mb_strlen($prefix));
            }
        }
        return $word;
    }
}

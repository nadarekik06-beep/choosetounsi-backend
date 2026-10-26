<?php

namespace App\Services\Catalog;

use Illuminate\Support\Str;

/**
 * Name comparison for category / subcategory imports (zero duplicates).
 *
 * Two names are the "same" when their normalized keys are equal:
 *   - case and accents ignored (Épicerie = epicerie)
 *   - "&", "et", "and", "de", "the"… and punctuation ignored
 *   - hyphens joined (T-shirt = Tshirt, Sweat-shirts = Sweatshirts)
 *   - plurals reduced (Robes = Robe, Dresses = Dress, Bijoux = Bijou, Accessories = Accessory)
 *   - word order ignored (Tops & Blouses = Blouses et tops)
 *   - Arabic: diacritics, alef / ya / ta-marbuta forms, "و" and "ال" prefixes ignored
 *
 * "Related" (token containment, e.g. Talons ⊂ Talons hauts, Sweat-shirts ⊂ Sweat-shirts & Hoodies)
 * is weaker: the import reports it as ambiguous instead of deciding.
 */
class CategoryNameMatcher
{
    private const STOPWORDS = [
        'et', 'and', 'de', 'des', 'du', 'd', 'la', 'le', 'les', 'l', 'en', 'a', 'au', 'aux',
        // not "the": accent-free French "thé" would vanish (Café & Thé ≠ Café)
        'pour', 'for', 'of', 'with', 'avec', 's', 'y', 'un', 'une',
        'و',
    ];

    /** Normalized tokens of a name (sorted, unique). */
    public function tokens(?string $name): array
    {
        $s = trim((string) $name);
        if ($s === '') return [];

        $isArabic = (bool) preg_match('/\p{Arabic}/u', $s);
        $s = $isArabic ? $this->arabic($s) : $this->latin($s);

        $out = [];
        foreach (preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY) as $t) {
            if (in_array($t, self::STOPWORDS, true)) continue;
            $t = $isArabic ? $this->arabicWord($t) : $this->singular($t);
            if ($t !== '') $out[$t] = true;
        }
        $out = array_keys($out);
        sort($out);
        return $out;
    }

    /** One comparable string; '' when the name is empty. */
    public function key(?string $name): string
    {
        return implode(' ', $this->tokens($name));
    }

    /** Keys of several names (FR / EN / AR / slug), empty ones dropped. */
    public function keys(array $names): array
    {
        $keys = [];
        foreach ($names as $n) {
            $k = $this->key($n);
            if ($k !== '') $keys[$k] = true;
        }
        return array_keys($keys);
    }

    /** Same thing: any key of $a equals any key of $b. */
    public function same(array $a, array $b): bool
    {
        return (bool) array_intersect($this->keys($a), $this->keys($b));
    }

    /** Related: one name's tokens contain the other's (Latin names only, at least one shared token). */
    public function related(array $a, array $b): bool
    {
        foreach ($a as $x) {
            if ($x === null || preg_match('/\p{Arabic}/u', (string) $x)) continue;
            $ta = $this->tokens($x);
            if (!$ta) continue;
            foreach ($b as $y) {
                if ($y === null || preg_match('/\p{Arabic}/u', (string) $y)) continue;
                $tb = $this->tokens($y);
                if (!$tb) continue;
                $common = array_intersect($ta, $tb);
                if ($common && (count($common) === count($ta) || count($common) === count($tb))) return true;
            }
        }
        return false;
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private function latin(string $s): string
    {
        $s = mb_strtolower($s);
        $s = Str::ascii($s);
        $s = str_replace(["'", '’', '`'], ' ', $s);
        $s = preg_replace('/(?<=\w)-(?=\w)/', '', $s);     // T-shirt → tshirt
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        return trim($s);
    }

    private function singular(string $w): string
    {
        if (strlen($w) <= 3 || ctype_digit($w)) return $w;
        if (str_ends_with($w, 'ies') && strlen($w) > 4) return substr($w, 0, -3) . 'y';        // accessories
        foreach (['sses', 'shes', 'ches', 'xes', 'zes'] as $end) {                               // dresses, watches
            if (str_ends_with($w, $end)) return substr($w, 0, -2);
        }
        if (str_ends_with($w, 'eaux')) return substr($w, 0, -1);                                 // chapeaux
        if (str_ends_with($w, 'oux') || str_ends_with($w, 'aux')) return substr($w, 0, -1);      // bijoux
        if (str_ends_with($w, 's') && !str_ends_with($w, 'ss') && !str_ends_with($w, 'us')) return substr($w, 0, -1);
        return $w;
    }

    private function arabic(string $s): string
    {
        $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s);   // tashkeel, tatweel
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);
        $s = preg_replace('/[^\p{Arabic}\p{N}a-zA-Z]+/u', ' ', $s);
        return trim(mb_strtolower($s));
    }

    private function arabicWord(string $w): string
    {
        if (mb_strlen($w) > 3 && mb_substr($w, 0, 1) === 'و') $w = mb_substr($w, 1);   // والملابس → الملابس
        if (mb_strlen($w) > 4 && mb_substr($w, 0, 2) === 'ال') $w = mb_substr($w, 2);   // الملابس → ملابس
        return $w;
    }
}

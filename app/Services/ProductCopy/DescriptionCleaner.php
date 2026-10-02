<?php

namespace App\Services\ProductCopy;

/**
 * Turns the model's JSON into safe, plain-text variants.
 *
 *  - strips markdown, HTML, emojis and bullet symbols the model may leak
 *  - removes sentences / bullets that use a banned cliché
 *  - removes sentences / bullets with a measured spec ("2100 W", "48h", "6 L")
 *    whose number is nowhere in the product data (invented specs)
 *  - drops variants that are empty, too thin, in the wrong language or near-duplicates
 *
 * Each kept variant reports what was fixed in `flags`.
 */
class DescriptionCleaner
{
    /** Why variants were dropped by the last clean() call (for logs). */
    public array $dropped = [];

    private const UNITS = 'w|kw|mah|ah|v|l|ml|cl|cm|mm|m|km|kg|g|mg|gb|go|tb|mb|hz|mhz|ghz|h|hrs?|hours?|heures?|min|minutes?|jours?|days?|ans?|years?|mois|months?|pouces?|inch(?:es)?|"|%|°c?|'
        . 'ساعة|ساعات|دقيقة|دقائق|يوم|أيام|سنة|سنوات|شهر|أشهر|سم|مم|م|كغ|كلغ|غ|غرام|لتر|مل|واط|فولت|جيغا|بالمائة';

    /**
     * @param  array  $raw      decoded model JSON
     * @param  array  $plan     DescriptionPrompt plan (language per variant)
     * @param  string $facts    the fact sheet the model received
     * @param  bool   $extras   keep seo_title / tags / social
     * @return array  list of clean variants
     */
    public function clean(array $raw, array $plan, string $facts, bool $extras): array
    {
        $items = $raw['variants'] ?? (isset($raw['intro']) ? [$raw] : []);
        if (!is_array($items)) return [];

        $this->dropped = [];
        $ctx           = $this->context($facts);
        $out         = [];
        $openings    = [];

        foreach (array_values($items) as $i => $item) {
            if (!is_array($item) || !isset($plan[$i])) continue;
            $lang  = $plan[$i]['language'];
            $flags = [];

            $intro   = $this->text($item['intro'] ?? '');
            $closing = $this->text($item['closing'] ?? '');
            $short   = $this->text($item['short'] ?? '');
            $bullets = array_values(array_filter(array_map(
                fn($b) => $this->bullet(is_string($b) ? $b : ''),
                is_array($item['bullets'] ?? null) ? $item['bullets'] : []
            )));

            // Clichés and invented specs: drop the sentence or bullet that carries them
            $intro   = $this->filterSentences($intro, $lang, $ctx, $flags);
            $closing = $this->filterSentences($closing, $lang, $ctx, $flags);
            // The card summary is short: when it is flagged, fall back to the intro's first sentence
            if ($short !== '' && $this->problem($short, $lang, $ctx)) $short = '';
            $kept = [];
            foreach ($bullets as $b) {
                $why = $this->problem($b, $lang, $ctx);
                if ($why) { $flags[$why] = true; continue; }
                $kept[] = $b;
            }
            $bullets = $kept;

            if ($short === '') $short = $this->firstSentence($intro);
            $short = $this->limit($short, 160);

            if (mb_strlen($intro) < 20 || count($bullets) < 2 || $closing === '') {
                $this->dropped[] = "#$i too_thin (bullets: " . count($bullets) . ')';
                continue;
            }
            if (!$this->isLanguage($intro . ' ' . implode(' ', $bullets) . ' ' . $closing, $lang)) {
                $this->dropped[] = "#$i wrong_language";
                continue;
            }

            $opening = $this->openingKey($intro);
            if (in_array($opening, $openings, true)) {   // same first words as another variant
                $this->dropped[] = "#$i duplicate_opening";
                continue;
            }
            $openings[] = $opening;

            $variant = [
                'id'                => 'v' . (count($out) + 1),
                'language'          => $lang,
                'hook_style'        => $plan[$i]['hook'],
                'intro'             => $intro,
                'bullets'           => $bullets,
                'closing'           => $closing,
                'description'       => $this->compose($intro, $bullets, $closing),
                'short_description' => $short,
                'flags'             => array_keys($flags),
            ];

            if ($extras) {
                $variant['seo_title'] = $this->limit($this->seoTitle($this->text($item['seo_title'] ?? ''), $lang, $ctx), 70);
                $variant['tags']      = $this->tags($item['tags'] ?? [], $facts);
                $social               = $this->text($item['social'] ?? '', keepHashtags: true, keepEmoji: true);
                $variant['social']    = $this->limit($this->filterSentences($social, $lang, $ctx, $flags), 280);
            }

            $out[] = $variant;
        }

        return $out;
    }

    /** Drop the " - …" segments of an SEO title that carry a cliché or an unsupported claim. */
    private function seoTitle(string $title, string $lang, array $ctx): string
    {
        $parts = preg_split('/\s+[-–—|]\s+/u', $title);
        $kept  = array_filter($parts, fn($p) => !$this->problem($p, $lang, $ctx));
        return $kept ? implode(' – ', $kept) : $title;
    }

    public function compose(string $intro, array $bullets, string $closing): string
    {
        return $intro . "\n\n" . implode("\n", array_map(fn($b) => '• ' . $b, $bullets)) . "\n\n" . $closing;
    }

    // ── Text hygiene ──────────────────────────────────────────────────────

    public function text(mixed $value, bool $keepHashtags = false, bool $keepEmoji = false): string
    {
        if (!is_string($value)) return '';
        $s = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/```[a-z]*|`/i', '', $s);
        $s = str_replace(['**', '__', '*'], '', $s);                                   // bold / italics markers
        $s = preg_replace('/^\s{0,3}#{1,6}\s+/mu', '', $s);                             // markdown headings
        if (!$keepHashtags) $s = preg_replace('/(?<!\w)#(?=\w)/u', '', $s);
        if (!$keepEmoji)    $s = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '', $s);
        $s = preg_replace('/^\s*(?:[-•*·▪►✓✔]|\d+[.)])\s+/mu', '', $s);                // list markers
        $s = preg_replace('/[ \t]+/u', ' ', $s);
        $s = preg_replace('/\s*\n\s*/u', ' ', $s);                                      // one paragraph per field
        return trim($s, " \t\n\r\0\x0B\"'«»");
    }

    private function bullet(string $value): string
    {
        $s = $this->text($value);
        return mb_strlen($s) >= 6 ? $s : '';
    }

    private function tags(mixed $tags, string $facts = ''): array
    {
        if (is_string($tags)) $tags = preg_split('/[,;\n]/u', $tags);
        if (!is_array($tags)) return [];

        // Proper nouns from the data (brand, store, place): a tag word a few letters away is a misspelling
        preg_match_all('/(?<![\p{L}\p{N}])\p{Lu}[\p{L}\p{N}]{4,}/u', $facts, $m);
        $known = array_values(array_unique(array_map('mb_strtolower', $m[0])));

        $out = [];
        foreach ($tags as $t) {
            if (!is_string($t)) continue;
            $t = mb_strtolower(trim($this->text($t), " #.,"));
            $t = implode(' ', array_map(fn($w) => $this->snap($w, $known), explode(' ', $t)));
            if ($t !== '' && mb_strlen($t) <= 40 && !in_array($t, $out, true)) $out[] = $t;
        }
        return array_slice($out, 0, 8);
    }

    /** "philippe" → "phillip" when the data spells the brand that way; plain words of 6+ letters only. */
    private function snap(string $word, array $known): string
    {
        if (!preg_match('/^[a-z0-9]{6,}$/', $word) || in_array($word, $known, true)) return $word;
        $max = strlen($word) >= 8 ? 3 : (strlen($word) === 7 ? 2 : 1);
        foreach ($known as $k) {
            if (preg_match('/^[a-z0-9]+$/', $k) && abs(strlen($k) - strlen($word)) <= $max && levenshtein($word, $k) <= $max) return $k;
        }
        return $word;
    }

    private function limit(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) return $s;
        $cut   = mb_substr($s, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');
        return rtrim($space > $max * 0.6 ? mb_substr($cut, 0, $space) : $cut, ' ,;:—-') . '…';
    }

    private function firstSentence(string $s): string
    {
        return preg_split('/(?<=[.!?؟])\s+/u', $s)[0] ?? $s;
    }

    private function openingKey(string $intro): string
    {
        $words = preg_split('/\s+/u', mb_strtolower($this->normalize($intro)));
        return implode(' ', array_slice($words, 0, 4));
    }

    // ── Clichés and invented specs ────────────────────────────────────────

    private function filterSentences(string $text, string $lang, array $ctx, array &$flags): string
    {
        if ($text === '') return '';
        $sentences = preg_split('/(?<=[.!?؟…])\s+/u', $text);
        $kept      = [];
        foreach ($sentences as $sentence) {
            $why = $this->problem($sentence, $lang, $ctx);
            if ($why) { $flags[$why] = true; continue; }
            $kept[] = $sentence;
        }
        // Never empty a field: when every sentence is flagged the text is kept and
        // the flag tells the seller to edit it before saving.
        if (!$kept) {
            $flags['needs_review'] = true;
            return $text;
        }
        return implode(' ', $kept);
    }

    /** 'banned_phrase' | 'unverified_claim' | 'unverified_spec' | null */
    public function problem(string $text, string $lang, array $ctx): ?string
    {
        return $this->diagnose($text, $lang, $ctx)[0] ?? null;
    }

    /** What the checks compare against: the numbers and the normalized text of the fact sheet. */
    public function context(string $facts): array
    {
        return ['numbers' => $this->numbers($facts), 'text' => $this->normalize($facts)];
    }

    /** @return array{0: string, 1: string}|null [reason, the phrase or spec at fault] */
    public function diagnose(string $text, string $lang, array $ctx): ?array
    {
        $hay   = $this->normalize($text);
        $lists = [
            'banned_phrase'     => array_merge(DescriptionPrompt::BANNED[$lang] ?? [], DescriptionPrompt::BANNED['en']),
            'unverified_claim'  => array_merge(DescriptionPrompt::CLAIMS[$lang] ?? [], DescriptionPrompt::CLAIMS['en']),
        ];
        foreach ($lists as $reason => $phrases) {
            foreach (array_unique($phrases) as $phrase) {
                $needle = $this->normalize($phrase);
                if (!preg_match('/(?<![\p{L}])' . preg_quote($needle, '/') . '/u', $hay)) continue;
                // A claim the seller wrote in the product data is a fact (clichés stay banned)
                if ($reason === 'unverified_claim' && str_contains($ctx['text'] ?? '', $needle)) continue;
                return [$reason, $phrase];
            }
        }

        if (preg_match_all('/(\d+(?:[.,\s]\d+)*)\s?(?:' . self::UNITS . ')(?![\p{L}])/iu', $this->digits($text), $m)) {
            foreach ($m[1] as $k => $number) {
                if (!array_intersect($this->numberKeys($number), $ctx['numbers'] ?? [])) return ['unverified_spec', trim($m[0][$k])];
            }
        }
        return null;
    }

    // ── Repair support ────────────────────────────────────────────────────

    /** Model JSON with every text field normalized, so issues and fixes match exactly. */
    public function prepare(array $raw): array
    {
        $items = $raw['variants'] ?? (isset($raw['intro']) ? [$raw] : []);
        if (!is_array($items)) return ['variants' => []];
        foreach ($items as $i => $item) {
            if (!is_array($item)) { unset($items[$i]); continue; }
            foreach (['intro', 'closing', 'short'] as $f) $items[$i][$f] = $this->text($item[$f] ?? '');
            if (isset($item['social'])) $items[$i]['social'] = $this->text($item['social'], keepHashtags: true, keepEmoji: true);
            $items[$i]['bullets'] = array_values(array_map(
                fn($b) => $this->text(is_string($b) ? $b : ''),
                is_array($item['bullets'] ?? null) ? $item['bullets'] : []
            ));
        }
        return ['variants' => array_values($items)];
    }

    /**
     * Sentences / bullets that use a banned phrase or an unverified spec.
     * @return array<int, array{text: string, problem: string, language: string}>
     */
    public function issues(array $prepared, array $plan, string $facts): array
    {
        $ctx = $this->context($facts);
        $found       = [];
        foreach ($prepared['variants'] as $i => $item) {
            $lang   = $plan[$i]['language'] ?? 'fr';
            $pieces = $item['bullets'];
            foreach (['intro', 'closing', 'short', 'social'] as $f) {
                if (($item[$f] ?? '') !== '') array_push($pieces, ...preg_split('/(?<=[.!?؟…])\s+/u', $item[$f]));
            }
            foreach ($pieces as $piece) {
                $why = $this->diagnose($piece, $lang, $ctx);
                if (!$why || isset($found[$piece])) continue;
                $found[$piece] = [
                    'text'     => $piece,
                    'problem'  => match ($why[0]) {
                        'banned_phrase'    => "uses the banned phrase \"{$why[1]}\"",
                        'unverified_claim' => "claims \"{$why[1]}\", which PRODUCT DATA does not support",
                        default            => "states \"{$why[1]}\", which is not in PRODUCT DATA",
                    },
                    'language' => $lang,
                ];
            }
        }
        return array_values($found);
    }

    /** Replace each original sentence by its fix everywhere it appears. */
    public function applyFixes(array $prepared, array $fixes): array
    {
        if (!$fixes) return $prepared;
        foreach ($prepared['variants'] as $i => $item) {
            foreach (['intro', 'closing', 'short', 'social'] as $f) {
                if (isset($item[$f])) $prepared['variants'][$i][$f] = strtr($item[$f], $fixes);
            }
            $prepared['variants'][$i]['bullets'] = array_map(fn($b) => $fixes[$b] ?? strtr($b, $fixes), $item['bullets']);
        }
        return $prepared;
    }

    /** Every number in the fact sheet, normalized ("2 100" → "2100", "6,50" → "6.5"). */
    public function numbers(string $facts): array
    {
        preg_match_all('/\d+(?:[.,\s]\d+)*/u', $this->digits($facts), $m);
        $out = [];
        foreach ($m[0] as $n) {
            array_push($out, ...$this->numberKeys($n));
            foreach (preg_split('/\s+/u', $n) as $part) array_push($out, ...$this->numberKeys($part));
        }
        return array_values(array_unique($out));
    }

    /** Possible readings of a number: "2,100" may be 2100 or 2.1, "84,500" may be 84500 or 84.5. */
    private function numberKeys(string $n): array
    {
        $n    = preg_replace('/\s+/u', '', $n);
        $keys = [];
        if (preg_match('/^\d{1,3}(?:[.,]\d{3})+$/', $n)) {
            $keys[] = $this->trimNumber(preg_replace('/\D/', '', $n));
        }
        if (substr_count($n, ',') + substr_count($n, '.') <= 1) {
            $keys[] = $this->trimNumber(str_replace(',', '.', $n));
        }
        return array_values(array_unique(array_filter($keys, fn($k) => $k !== '')));
    }

    private function trimNumber(string $n): string
    {
        if (str_contains($n, '.')) $n = rtrim(rtrim($n, '0'), '.');
        $n = ltrim($n, '0');
        return $n === '' || $n[0] === '.' ? '0' . $n : $n;
    }

    private function digits(string $s): string
    {
        return strtr($s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => ',', "\u{00A0}" => ' ', "\u{202F}" => ' ']);
    }

    /** Lowercase, apostrophes unified, Arabic diacritics and tatweel removed. */
    private function normalize(string $s): string
    {
        $s = mb_strtolower($s);
        $s = str_replace(['’', '‘', 'ʼ'], "'", $s);
        $s = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $s);
        $s = str_replace(['أ', 'إ', 'آ'], 'ا', $s);
        // Arabic definite article: "الجلد الطبيعي" must match "جلد طبيعي"
        return preg_replace('/(?<![\p{L}])(?:[وفب]?ال|لل)(?=\p{Arabic}{2,})/u', '', $s);
    }

    // ── Language check ────────────────────────────────────────────────────

    public function isLanguage(string $text, string $lang): bool
    {
        $letters = preg_match_all('/\p{L}/u', $text);
        if ($letters < 20) return false;
        $arabic = preg_match_all('/\p{Arabic}/u', $text);

        if ($lang === 'ar') return $arabic / $letters >= 0.6;
        if ($arabic / $letters > 0.2) return false;

        $words = preg_split('/[^\p{L}\']+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $fr    = count(array_intersect($words, ['le', 'la', 'les', 'des', 'du', 'et', 'pour', 'vous', 'votre', 'avec', 'une', 'un', 'est', 'dans', 'sur', 'tu', 'ton', 'ta', 'qui', 'en', 'au', 'aux', 'à', 'ce', 'cette']));
        $en    = count(array_intersect($words, ['the', 'and', 'for', 'you', 'your', 'with', 'a', 'an', 'is', 'in', 'on', 'it', 'its', 'of', 'to', 'that', 'this', 'from', 'at', 'or']));

        return $lang === 'fr' ? $fr >= $en : $en >= $fr;
    }
}

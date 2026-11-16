<?php

namespace App\Services\Chat;

/**
 * Rule-based, instant, free language + dialect detection (one message at a time).
 *
 *   tn_arabizi  Tunisian in Latin letters + digits   ("nheb nechri sabat")
 *   tn_arabic   Tunisian in Arabic script            ("نحب نشري سباط")
 *   msa         Modern Standard Arabic
 *   fr | en
 *
 * Tunisian signals win on mixed messages. null = no signal at all ("iphone 13").
 * Word lists live in config/chatbot_darija.php (signals).
 */
class LanguageDetector
{
    public const DIALECTS = ['tn_arabizi', 'tn_arabic', 'msa', 'fr', 'en'];

    /** Short words that are also French / English / Spanish words: they only count together with another signal. */
    private const WEAK = ['el', 'win', 'sak', 'bel', 'bil', 'mel', 'mra', 'bent', 'weld', 'omi', 'taw', 'nsa', 'ring', 'la', 'ma', 'bch'];

    public function __construct(private ChatLexicon $lex) {}

    public function detect(string $message): ?string
    {
        $s = $this->scores($message);

        if ($s['arabic'] > 0.3) {
            if ($s['tn_ar'] > 0) {
                return 'tn_arabic';
            }
            return $s['msa'] > 0 ? 'msa' : 'tn_arabic';
        }

        if ($s['arabizi_strong'] > 0 || $s['arabizi_weak'] >= 2) {
            return 'tn_arabizi';
        }
        if ($s['fr'] === $s['en']) {
            return $s['fr'] > 0 ? null : null;
        }

        return $s['fr'] > $s['en'] ? 'fr' : 'en';
    }

    /** The language of the storefront UI as a dialect (used when the message has no signal). */
    public function fromLocale(?string $locale): string
    {
        return match ($locale) {
            'ar' => 'tn_arabic',
            'en' => 'en',
            default => 'fr',
        };
    }

    /** fr | en | ar — what the frontend and the knowledge base understand. */
    public static function baseLanguage(string $dialect): string
    {
        return match ($dialect) {
            'tn_arabizi', 'tn_arabic', 'msa' => 'ar',
            default => $dialect,
        };
    }

    public static function isArabicScript(string $dialect): bool
    {
        return in_array($dialect, ['tn_arabic', 'msa'], true);
    }

    /**
     * Does a generated reply look like it is written in $dialect?
     * Product names (usually French) are removed first.
     *
     * @param string[] $ignore texts to ignore (product names, brands)
     */
    public function replyMatches(string $reply, string $dialect, array $ignore = []): bool
    {
        foreach ($ignore as $name) {
            if ($name !== '') {
                $reply = str_ireplace($name, ' ', $reply);
            }
        }
        // Standalone amounts only: the digits inside arabizi words (y3aychek, 7aja) are letters
        $reply = preg_replace('/(?<![\p{L}\p{N}])\d+(?:[.,]\d+)?(?![\p{L}\p{N}])\s*(?:dt|tnd|د\.?\s?ت|دينار)?/iu', ' ', $reply);
        $s = $this->scores($reply);

        return match ($dialect) {
            // Arabic script only: no Latin Darija words inside (product names are removed above)
            'tn_arabic', 'msa' => $s['arabic'] >= 0.6 && $s['arabizi_strong'] === 0,
            'tn_arabizi'       => $s['arabic'] < 0.1 && ($s['arabizi_strong'] + $s['arabizi_weak']) >= 1
                                  && ($s['arabizi_strong'] + $s['arabizi_weak']) * 2 >= $s['fr'],
            'fr'               => $s['arabic'] < 0.1 && $s['arabizi_strong'] === 0 && $s['fr'] >= $s['en'],
            default            => $s['arabic'] < 0.1 && $s['arabizi_strong'] === 0 && $s['en'] >= $s['fr'],
        };
    }

    /** @return array{arabic: float, tn_ar: int, msa: int, arabizi_strong: int, arabizi_weak: int, fr: int, en: int} */
    public function scores(string $message): array
    {
        // Order numbers / SKUs ("ORD-X3YZ7K9A") are not arabizi
        $clean = preg_replace('/\b(?=[A-Z0-9-]*\d)[A-Z][A-Z0-9-]{3,}\b/', ' ', $message);
        $text  = $this->lex->norm($clean);

        preg_match_all('/\p{Arabic}/u', $text, $ar);
        preg_match_all('/\p{L}/u', $text, $letters);
        $ratio = count($letters[0]) ? count($ar[0]) / count($letters[0]) : 0.0;

        $words = preg_split('/[^\p{L}\p{N}\']+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        $strong = $weak = 0;
        $arabizi = array_flip(array_map(fn ($w) => $this->lex->norm($w), $this->lex->config('signals.arabizi', [])));
        $seen = [];
        foreach ($words as $w) {
            if (!preg_match('/^[a-z0-9\']+$/', $w)) {
                continue;
            }
            $isDigitWord = (bool) preg_match('/^[a-z]*[a-z][2379][a-z]+$|^[3579][a-z]{3,}$/', $w);
            if (isset($arabizi[$w]) || $isDigitWord) {
                if (in_array($w, self::WEAK, true) && !$isDigitWord) {
                    $weak++;
                } else {
                    $strong++;
                }
                $seen[] = $w;
            }
        }

        $count = fn (array $list) => count(array_intersect($words, array_map(fn ($x) => $this->lex->norm($x), $list)));

        return [
            'arabic'         => $ratio,
            'tn_ar'          => count($this->lex->findAll($text, $this->lex->config('signals.arabic', []))),
            'msa'            => count($this->lex->findAll($text, $this->lex->config('signals.msa', []))),
            'arabizi_strong' => $strong,
            'arabizi_weak'   => $weak,
            'fr'             => $count($this->lex->config('signals.fr', [])) + (preg_match('/[éèêàçùûôî]/u', $text) ? 2 : 0),
            'en'             => $count($this->lex->config('signals.en', [])),
        ];
    }
}

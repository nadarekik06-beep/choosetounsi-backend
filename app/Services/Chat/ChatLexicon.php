<?php

namespace App\Services\Chat;

/**
 * Reads config/chatbot_darija.php and matches its words against a message.
 *
 * Messages and lexicon words go through the same norm(), so "أحمر" / "احمر" /
 * "ڨ" / "ة" spelling variants match. Latin words match as whole words; Arabic
 * words also match with the glued prefixes ال ل لل ب و ف ك ("لصاحبتي" = ل + صاحبتي).
 */
class ChatLexicon
{
    /** @var array<string,string> */
    private array $regexCache = [];

    public function config(string $path, $default = null)
    {
        return config('chatbot_darija.' . $path, $default);
    }

    /** Lower-case, Western digits, Arabic spelling variants merged, repeated letters collapsed. */
    public function norm(string $text): string
    {
        $text = strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '’' => "'", '‘' => "'", '،' => ',', '؟' => '?', 'ـ' => '',
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ڨ' => 'ق', 'گ' => 'ق', 'ڤ' => 'ف', 'پ' => 'ب',
        ]);
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text);
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/(\p{L})\1{2,}/u', '$1$1', $text);

        return preg_replace('/\s+/u', ' ', $text);
    }

    /** The first listed word found in the (already norm()ed) text, or null. */
    public function find(string $text, array $words): ?string
    {
        if (!$words) {
            return null;
        }
        if (preg_match($this->regex($words), $text, $m)) {
            return $m['w'];
        }
        return null;
    }

    public function has(string $text, array $words): bool
    {
        return $this->find($text, $words) !== null;
    }

    /** Every listed word found in the text. */
    public function findAll(string $text, array $words): array
    {
        if (!$words) {
            return [];
        }
        preg_match_all($this->regex($words), $text, $m);
        return array_values(array_unique($m['w']));
    }

    /** Text without the given words (used to find the free-text product words). */
    public function strip(string $text, array $words): string
    {
        return $words ? preg_replace($this->regex($words), ' ', $text) : $text;
    }

    private function regex(array $words): string
    {
        $key = md5(implode("\x1f", $words));
        if (!isset($this->regexCache[$key])) {
            $alts = array_map(fn ($w) => preg_quote($this->norm($w), '/'), $words);
            usort($alts, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
            $this->regexCache[$key] = '/(?<![\p{L}\p{N}])(?:ال|لل|ل|ب|و|ف|ك)?(?<w>' . implode('|', array_unique($alts)) . ')(?![\p{L}\p{N}])/u';
        }
        return $this->regexCache[$key];
    }
}

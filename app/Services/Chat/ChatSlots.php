<?php

namespace App\Services\Chat;

/**
 * Deterministic slot extraction (the "rules" half of step B).
 *
 *   recipient   girlfriend | wife | mother | sister | boyfriend | husband | father | brother | girl | boy | child | friend_f
 *   gender      women | men | kids | null      (explicit "femme / lel rjel" beats the recipient)
 *   child       true when the shopper buys for a child (kids' subcategories are then allowed)
 *   concept     key of config('chatbot_darija.concepts'), e.g. "shoes"
 *   colors      canonical colors (black, white, red ...)
 *   sizes       ["42", "M"]
 *   only_promo  true when the customer asks for promotions
 *   sort        price_asc | price_desc | newest | popular | null
 *   consumed    normalised words that were understood (not free-text product words)
 *
 * Prices are parsed by IntentExtractor::parsePrice().
 */
class ChatSlots
{
    public function __construct(private ChatLexicon $lex) {}

    public function parse(string $message): array
    {
        $text = $this->lex->norm($message);
        $consumed = [];

        // ── Recipient + gender ───────────────────────────────────────────
        $recipient = null;
        $gender    = null;
        foreach ($this->lex->config('recipients', []) as $name => [$g, $words]) {
            if ($word = $this->lex->find($text, $words)) {
                $recipient = $name;
                $gender    = in_array($g, ['kids_girl', 'kids_boy', 'kids'], true) ? 'kids' : $g;
                $consumed[] = $word;
                // a grown-up recipient is a stronger signal than a later child word
                if ($gender !== 'kids') {
                    break;
                }
            }
        }

        $explicit = null;
        foreach ($this->lex->config('gender', []) as $g => $words) {
            if ($word = $this->lex->find($text, $words)) {
                $explicit   = $g;
                $consumed[] = $word;
                break;
            }
        }
        if ($explicit) {
            $gender = $explicit;
        }

        // ── Product concept (earliest mention wins) ──────────────────────
        $concept = null;
        $bestPos = PHP_INT_MAX;
        foreach ($this->lex->config('concepts', []) as $key => $def) {
            foreach ($this->lex->findAll($text, $def['terms']) as $word) {
                $pos = mb_strpos($text, $word);
                if ($pos !== false && $pos < $bestPos) {
                    $bestPos = $pos;
                    $concept = $key;
                }
                $consumed[] = $word;
            }
        }

        // ── Colors ───────────────────────────────────────────────────────
        $colors = [];
        foreach ($this->lex->config('colors', []) as $canonical => $def) {
            if ($found = $this->lex->findAll($text, $def['words'])) {
                $colors[]   = $canonical;
                $consumed   = array_merge($consumed, $found);
            }
        }

        // ── Sizes ────────────────────────────────────────────────────────
        $sizes = [];
        if (preg_match_all('/(?:taille|pointure|size|9ias|9yes|nomra|numero|numéro|مقاس|قياس|نمره)\s*(?:de|mte3i|:)?\s*(xxs|xs|xxl|xl|s|m|l|\d{2}(?:[.,]5)?)(?![\p{L}\p{N}])/u', $text, $m)) {
            foreach ($m[1] as $size) {
                $size = strtoupper(str_replace(',', '.', $size));
                if (!ctype_digit($size) || ((int) $size >= 20 && (int) $size <= 60)) {
                    $sizes[] = $size;
                }
            }
        }

        // ── Price words ──────────────────────────────────────────────────
        $sort  = null;
        $promo = false;
        foreach ($this->lex->config('price', []) as $kind => $words) {
            if ($word = $this->lex->find($text, $words)) {
                $consumed[] = $word;
                match ($kind) {
                    'cheap'     => $sort ??= 'price_asc',
                    'expensive' => $sort ??= 'price_desc',
                    'newest'    => $sort ??= 'newest',
                    'popular'   => $sort ??= 'popular',
                    'promo'     => $promo = true,
                    default     => null,
                };
            }
        }

        return [
            'recipient'  => $recipient,
            'gender'     => $gender,
            'child'      => $gender === 'kids',
            'concept'    => $concept,
            'colors'     => array_values(array_unique($colors)),
            'sizes'      => array_values(array_unique($sizes)),
            'only_promo' => $promo,
            'sort'       => $sort,
            'consumed'   => array_values(array_unique($consumed)),
        ];
    }
}

<?php

namespace App\Services\Chat;

/**
 * Rule-based small talk, checked BEFORE product search (no Groq call):
 * greeting, "do you speak Tunisian?", founders, identity, thanks, goodbye.
 *
 * Phrases and replies live in config/chatbot_smalltalk.php. Matching ignores case,
 * punctuation, accents, repeated letters ("salemmm") and extra words around the phrase.
 *
 * match() returns null or:
 *   ['type' => greeting|speaks_tounsi|founders|identity|thanks|bye, 'salam' => bool, 'rest' => string]
 * `rest` is what is left of a greeting message once the greeting words are removed
 * ("salem, n7eb sabat" → "n7eb sabat"). An empty rest is a pure greeting.
 */
class SmallTalk
{
    private const ACCENTS = [
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ô' => 'o', 'ö' => 'o', 'î' => 'i', 'ï' => 'i', 'ç' => 'c',
    ];

    /** @var array<string,string> */
    private array $cache = [];

    public function __construct(private ChatLexicon $lex) {}

    public function match(string $message): ?array
    {
        $norm  = $this->lex->norm($message);
        $plain = $this->plain($norm);
        if (trim(preg_replace('/[\W_]+/u', '', $plain)) === '') {
            return null;
        }
        $tokens = count(preg_split('/[\W_]+/u', $plain, -1, PREG_SPLIT_NO_EMPTY));

        foreach (['speaks_tounsi', 'identity'] as $type) {
            if ($this->has($plain, config("chatbot_smalltalk.{$type}", []))) {
                return ['type' => $type, 'salam' => false, 'rest' => ''];
            }
        }
        if ($this->isFounders($plain)) {
            return ['type' => 'founders', 'salam' => false, 'rest' => ''];
        }
        // Thanks / goodbye only as a short message: "merci, je cherche une robe" is a product request
        if ($tokens <= 6) {
            foreach (['thanks', 'bye'] as $type) {
                if ($this->has($plain, config("chatbot_smalltalk.{$type}", []))) {
                    return ['type' => $type, 'salam' => false, 'rest' => ''];
                }
            }
        }

        return $this->greeting($norm, $plain);
    }

    /** Words (single tokens) used by greetings: never product keywords. */
    public function vocabulary(): array
    {
        $words = [];
        foreach (['greeting', 'salam', 'greeting_filler', 'thanks', 'bye'] as $key) {
            foreach (config("chatbot_smalltalk.{$key}", []) as $phrase) {
                foreach (preg_split('/\s+/u', $this->lex->norm($phrase), -1, PREG_SPLIT_NO_EMPTY) as $w) {
                    $words[] = $w;
                }
            }
        }

        return array_values(array_unique($words));
    }

    public function reply(string $key, string $dialect): string
    {
        $variants = config("chatbot_smalltalk.replies.{$key}", []);

        return $variants[$dialect] ?? ($dialect === 'msa' ? ($variants['tn_arabic'] ?? null) : null) ?? $variants['fr'] ?? '';
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function isFounders(string $plain): bool
    {
        $cfg = config('chatbot_smalltalk.founders', []);
        if ($this->has($plain, $cfg['any'] ?? [])) {
            return true;
        }

        return $this->has($plain, $cfg['who'] ?? []) && $this->has($plain, $cfg['did'] ?? []) && $this->has($plain, $cfg['brand'] ?? []);
    }

    private function greeting(string $norm, string $plain): ?array
    {
        $greet  = $this->regex(config('chatbot_smalltalk.greeting', []));
        $filler = $this->regex(config('chatbot_smalltalk.greeting_filler', []));
        $salam  = $this->regex(config('chatbot_smalltalk.salam', []));

        $cursor   = 0;
        $greeted  = false;
        $isSalam  = false;
        while (true) {
            $rest = mb_substr($plain, $cursor);
            if (preg_match('/^[\W_]*(?:' . $greet . ')(?![\p{L}\p{N}])/u', $rest, $m)) {
                $greeted = true;
                $isSalam = $isSalam || preg_match('/(?<![\p{L}\p{N}])(?:' . $salam . ')(?![\p{L}\p{N}])/u', $m[0]);
            } elseif ($greeted && preg_match('/^[\W_]*(?:' . $filler . ')(?![\p{L}\p{N}])/u', $rest, $m)) {
                $isSalam = $isSalam || preg_match('/^[\W_]*(?:' . $salam . ')(?![\p{L}\p{N}])/u', $m[0]);
            } else {
                break;
            }
            $cursor += mb_strlen($m[0]);
        }

        if (!$greeted) {
            return null;
        }

        $rest = trim(preg_replace('/^[\W_]+/u', '', mb_substr($norm, $cursor)));

        return ['type' => 'greeting', 'salam' => (bool) $isSalam, 'rest' => $rest];
    }

    private function has(string $plain, array $phrases): bool
    {
        return $phrases && preg_match('/(?<![\p{L}\p{N}])(?:' . $this->regex($phrases) . ')(?![\p{L}\p{N}])/u', $plain);
    }

    /** Alternation of the phrases: letters may repeat, spaces / punctuation are flexible. */
    private function regex(array $phrases): string
    {
        $key = md5(implode("\x1f", $phrases));
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $alts = [];
        foreach ($phrases as $phrase) {
            $p   = $this->plain($this->lex->norm($phrase));
            $out = '';
            foreach (mb_str_split($p) as $ch) {
                if (preg_match('/[\p{L}\p{N}]/u', $ch)) {
                    $out .= preg_quote($ch, '/') . '+';
                } elseif (preg_match('/\s/u', $ch)) {
                    $out .= '[\W_]+';
                } else {
                    $out .= '[\W_]*';
                }
            }
            if ($out !== '') {
                $alts[] = [mb_strlen($p), $out];
            }
        }
        usort($alts, fn ($a, $b) => $b[0] <=> $a[0]);

        return $this->cache[$key] = implode('|', array_unique(array_column($alts, 1)));
    }

    /** Accents removed, one character in → one character out (so offsets stay aligned). */
    private function plain(string $text): string
    {
        return strtr($text, self::ACCENTS);
    }
}

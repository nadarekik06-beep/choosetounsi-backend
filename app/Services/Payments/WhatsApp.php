<?php

namespace App\Services\Payments;

/** wa.me links: https://wa.me/<digits>?text=<RFC 3986-encoded UTF-8 text>. */
class WhatsApp
{
    public static function link(string $digits, ?string $text = null): ?string
    {
        if ($digits === '') {
            return null;
        }
        return "https://wa.me/{$digits}" . ($text !== null && $text !== '' ? '?text=' . rawurlencode($text) : '');
    }

    /**
     * International digits for wa.me: "+216 57 252 576" / "0021657252576" → "21657252576";
     * a local 8-digit Tunisian number gets the 216 prefix. Null when it can't be a phone number.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 8) {
            $digits = '216' . $digits;
        }
        return strlen($digits) >= 10 && strlen($digits) <= 15 ? $digits : null;
    }

    /** Fill {placeholders}; unknown ones are left as they are. */
    public static function render(string $template, array $values): string
    {
        return strtr($template, collect($values)->mapWithKeys(fn ($v, $k) => ['{' . $k . '}' => (string) $v])->all());
    }
}

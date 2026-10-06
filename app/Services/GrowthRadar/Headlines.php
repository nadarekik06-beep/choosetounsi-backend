<?php

namespace App\Services\GrowthRadar;

use App\Services\Chat\GroqClient;
use App\Services\Forecast\AiNarrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Optional Groq rewrite of a card's headline (free tier, one short call per new
 * card and language). The rewrite must keep every number of the template and
 * add none (same check as the forecast narrator), else it is dropped and the
 * template headline is shown. Never blocks: on any failure the card simply
 * keeps its template text.
 */
class Headlines
{
    public function __construct(private GroqClient $groq, private AiNarrator $narrator, private Presenter $presenter) {}

    public function enabled(): bool
    {
        return (bool) config('growth.ai_headlines') && $this->groq->isConfigured();
    }

    public function rewrite(object $row, string $locale): ?string
    {
        if (!$this->enabled()) return null;
        $payload = json_decode($row->payload, true) ?: [];
        $params = $this->presenter->params($payload['params'] ?? [], $locale);
        [$template] = $this->presenter->texts($row->type, $params, $payload['action'] ?? []);

        $lang = ['fr' => 'French', 'en' => 'English', 'ar' => 'Modern Standard Arabic (simple words)'][$locale] ?? 'French';
        $text = $this->groq->chat([
            ['role' => 'system', 'content' => "Rewrite a one-line business tip for a small Tunisian online seller in {$lang}: plain, friendly, direct, max 90 characters, no emoji, no quotes, no markdown. Keep every number exactly as given and add no other number. Keep the product name as given. Answer with the line only."],
            ['role' => 'user', 'content' => $template],
        ], 'growth_headline', false, 80);
        if ($text === null) return null;

        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)), " \"'«»“”");
        if ($text === '' || mb_strlen($text) > 120) return null;

        preg_match_all('/\d+(?:[.,]\d+)?/', $template, $m);
        $facts = array_map(fn ($n) => (float) str_replace(',', '.', $n), $m[0]);
        if (!$this->narrator->numbersAllowed($text, $facts) || !$this->keepsNumbers($template, $text)) {
            Log::info('[GrowthRadar] Rejected AI headline', ['template' => $template, 'text' => $text]);
            return null;
        }
        return $text;
    }

    /** Store the rewrite for this locale (null = keep the template). */
    public function fill(object $row, string $locale): void
    {
        $existing = $row->headlines ? json_decode($row->headlines, true) : [];
        if (array_key_exists($locale, $existing)) return;
        try {
            $existing[$locale] = $this->rewrite($row, $locale);
        } catch (\Throwable $e) {
            $existing[$locale] = null;
        }
        DB::table('growth_cards')->where('id', $row->id)->update(['headlines' => json_encode($existing, JSON_UNESCAPED_UNICODE)]);
    }

    /** Every number of the template is still in the rewrite. */
    private function keepsNumbers(string $template, string $text): bool
    {
        $norm = fn ($s) => strtr($s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        preg_match_all('/\d+/', $norm($template), $a);
        preg_match_all('/\d+/', $norm($text), $b);
        return !array_diff($a[0], $b[0]);
    }
}

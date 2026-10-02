<?php

namespace App\Services\Forecast;

use App\Services\Chat\GroqClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 2–3 sentence explanation of a forecast in the seller's language.
 *
 * Groq only rewrites the numbers we computed (passed as JSON); any number in
 * its answer that isn't one of ours rejects the answer. Cached per product,
 * language and snapshot day — Groq is called at most once per product/day/
 * language. If Groq is off, rate-limited or rejected, a template text built
 * from the same numbers is returned: the page never breaks.
 */
class AiNarrator
{
    public function __construct(private GroqClient $groq) {}

    /** @return array{text: string, source: 'ai'|'template'} */
    public function explain(int $sellerId, int $productId, array $payload, string $locale): array
    {
        $facts = $this->facts($payload, $locale);
        $date  = $payload['snapshot_date'] ?? CarbonImmutable::now()->toDateString();
        $key   = "forecast_ai:$sellerId:$productId:$locale:$date:" . md5(json_encode($facts));

        $cached = Cache::get($key);
        if ($cached) return $cached;

        $text = $this->askGroq($facts, $locale);
        $result = $text !== null
            ? ['text' => $text, 'source' => 'ai']
            : ['text' => $this->template($facts, $locale), 'source' => 'template'];

        // Templates are cheap: cache them briefly so a recovered Groq gets a chance later today.
        $ttl = $result['source'] === 'ai'
            ? CarbonImmutable::now(config('forecast.timezone'))->endOfDay()
            : now()->addMinutes(30);
        Cache::put($key, $result, $ttl);
        return $result;
    }

    /** The only numbers the text may contain. */
    public function facts(array $p, string $locale): array
    {
        $isShop = ($p['scope'] ?? '') === 'shop';
        $event  = collect($p['events'] ?? [])->first(fn($e) => $e['days_until'] >= 0 && $e['days_until'] <= 42);
        $stock  = $p['stock'] ?? [];
        // Nearest stock-out among the product or its variants
        $soon   = collect($p['variants'] ?? [])->pluck('stock')->push($stock)
            ->filter(fn($s) => ($s['days_left'] ?? null) !== null)->sortBy('days_left')->first();
        $risk   = collect($p['variants'] ?? [])->filter(fn($v) => $v['stock']['days_left'] !== null)->sortBy('stock.days_left')->first();

        return array_filter([
            'product'          => $isShop ? __('forecast.shop_name', [], $locale) : ($p['product_name'] ?? ''),
            'tier'             => $p['tier'],
            'reliability'      => $p['confidence']['level'] ?? 'none',
            'confidence_score' => $p['confidence']['score'] ?? 0,
            'orders'           => $p['confidence']['orders'] ?? ($p['data']['orders'] ?? 0),
            'days_of_history'  => $p['confidence']['days'] ?? ($p['data']['days'] ?? null),
            'next_4_weeks_units_low'  => $p['next28']['low'] ?? null,
            'next_4_weeks_units_high' => $p['next28']['high'] ?? null,
            'next_4_weeks_revenue_low_tnd'  => isset($p['next28']) ? (int) round($p['next28']['revenue_low']) : null,
            'next_4_weeks_revenue_high_tnd' => isset($p['next28']) ? (int) round($p['next28']['revenue_high']) : null,
            'current_stock'    => $isShop ? null : ($stock['current'] ?? null),
            'stockout_in_days' => $isShop ? null : ($soon['days_left'] ?? null),
            'stockout_date'    => $isShop ? null : ($soon['stockout_date'] ?? null),
            'stockout_variant' => $risk ? ($risk['labels'][$locale] ?? null) : null,
            'reorder_units'    => $isShop ? null : (($soon['reorder_qty'] ?? 0) ?: null),
            'reorder_before'   => $isShop ? null : ($soon['reorder_by'] ?? null),
            'products_to_restock' => $isShop ? count($stock['at_risk'] ?? []) : null,
            'next_event'       => $event['names'][$locale] ?? null,
            'next_event_in_days' => $event['days_until'] ?? null,
            'next_event_measured_change_pct' => isset($event['effect']['change_pct']) && $event['applied'] ? (int) round($event['effect']['change_pct']) : null,
            'views_last_30_days' => $p['signals']['views_30d'] ?? null,
            'last_month_forecast_low'  => $p['accuracy']['low'] ?? null,
            'last_month_forecast_high' => $p['accuracy']['high'] ?? null,
            'last_month_actual'        => $p['accuracy']['actual'] ?? null,
        ], fn($v) => $v !== null && $v !== '');
    }

    private function askGroq(array $facts, string $locale): ?string
    {
        if (!$this->groq->isConfigured()) return null;

        $lang = ['fr' => 'French', 'en' => 'English', 'ar' => 'Modern Standard Arabic (simple words)'][$locale] ?? 'French';
        $system = "You explain a sales forecast to a small seller on a Tunisian marketplace. Write in {$lang}, 2 or 3 short sentences, plain text, no markdown, no greeting.\n"
            . "Use ONLY the numbers in the JSON. Never calculate, round differently or invent numbers, percentages or dates. Give quantities as ranges (low–high) exactly as given; money is in Tunisian dinars (DT).\n"
            . "tier meanings: category = estimate from similar products (say so), blend = seller data mixed with similar products, limited = very few orders, own = seller's own history, insufficient = not enough data (say it plainly and suggest working on photos, description, price).\n"
            . "Mention the reliability level. If a stock-out is given, say when and what to reorder. If an event is given, mention it; only cite a percentage if next_event_measured_change_pct is present.";

        $text = $this->groq->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => json_encode($facts, JSON_UNESCAPED_UNICODE)],
        ], 'forecast_explain', false, 350);

        if ($text === null) return null;
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)));
        if ($text === '' || mb_strlen($text) > 900) return null;

        if (!$this->numbersAllowed($text, $facts)) {
            Log::info('[Forecast AI] Rejected text with numbers not in the facts', ['text' => $text]);
            return null;
        }
        return $text;
    }

    /** Every number in the text must be one of ours (dates split into parts). */
    public function numbersAllowed(string $text, array $facts): bool
    {
        $text = strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', ' ' => '', ' ' => '']);
        $allowed = ['2', '3', '4', '14', '28', '30'];   // "4 semaines", "2–3", "14 jours"…
        foreach ($facts as $v) {
            if (is_int($v) || is_float($v)) {
                $allowed[] = (string) (int) round($v);
                $allowed[] = number_format((float) $v, 0, '', '');
            } elseif (is_string($v) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
                array_push($allowed, $m[1], $m[2], $m[3], ltrim($m[2], '0'), ltrim($m[3], '0'));
            } elseif (is_string($v) && preg_match_all('/\d+/', $v, $inName)) {
                array_push($allowed, ...$inName[0]);   // "PS 5", "Dumbbells 10k"
            }
        }
        preg_match_all('/\d+(?:[.,]\d+)?/', $text, $found);
        foreach ($found[0] as $n) {
            $int = (string) (int) round((float) str_replace(',', '.', $n));
            if (!in_array($n, $allowed, true) && !in_array($int, $allowed, true)) return false;
        }
        return true;
    }

    public function template(array $f, string $locale): string
    {
        $t = fn(string $key, array $r = []) => __("forecast.ai.$key", $r, $locale);
        $name = $f['product'] ?? '';
        $parts = [];

        if ($f['tier'] === 'insufficient') {
            return $t('insufficient', ['name' => $name]) . ' ' . $t('insufficient_tip');
        }
        if ($f['tier'] === 'category') {
            $parts[] = $t('category', ['name' => $name, 'low' => $f['next_4_weeks_units_low'], 'high' => $f['next_4_weeks_units_high']]);
        } else {
            $parts[] = $t('forecast', [
                'name' => $name, 'low' => $f['next_4_weeks_units_low'], 'high' => $f['next_4_weeks_units_high'],
                'rev_low' => $f['next_4_weeks_revenue_low_tnd'] ?? 0, 'rev_high' => $f['next_4_weeks_revenue_high_tnd'] ?? 0,
            ]);
            $parts[] = $t('reliability', [
                'level' => __("forecast.levels.{$f['reliability']}", [], $locale),
                'orders' => $f['orders'] ?? 0, 'days' => $f['days_of_history'] ?? 0,
            ]);
        }

        if (isset($f['stockout_in_days'], $f['reorder_units'], $f['reorder_before'])) {
            $parts[] = $t('stockout', ['date' => $this->date($f['stockout_date'], $locale), 'qty' => $f['reorder_units'], 'by' => $this->date($f['reorder_before'], $locale)]);
        } elseif (isset($f['current_stock']) && $f['current_stock'] > 0) {
            $parts[] = $t('stock_ok', ['stock' => $f['current_stock']]);
        }

        if (isset($f['next_event'])) {
            $s = $t('event', ['event' => $f['next_event'], 'days' => $f['next_event_in_days']]);
            if (isset($f['next_event_measured_change_pct'])) {
                $pct = $f['next_event_measured_change_pct'];
                $s .= ' ' . $t('event_effect', ['pct' => ($pct > 0 ? '+' : '') . $pct]);
            }
            $parts[] = $s;
        }
        return implode(' ', array_slice($parts, 0, 3));
    }

    private function date(string $ymd, string $locale): string
    {
        return CarbonImmutable::parse($ymd)->locale($locale)->translatedFormat('j F');
    }
}

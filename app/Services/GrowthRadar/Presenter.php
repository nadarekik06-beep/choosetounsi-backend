<?php

namespace App\Services\GrowthRadar;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Shapes cards, results and the score for the dashboard, in the request's language.
 */
class Presenter
{
    private array $products = [];

    /** @param object[] $rows growth_cards rows */
    public function cards(array $rows, string $locale): array
    {
        $this->loadProducts(array_filter(array_map(fn ($r) => $r->product_id, $rows)));
        return array_map(fn ($r) => $this->card($r, $locale), $rows);
    }

    public function card(object $row, string $locale): array
    {
        $p = json_decode($row->payload, true) ?: [];
        $params = $this->params($p['params'] ?? [], $locale);
        $type = $row->type;
        [$headline, $recommendation] = $this->texts($type, $params, $p['action'] ?? []);
        $ai = $row->headlines ? (json_decode($row->headlines, true)[$locale] ?? null) : null;

        $basis = array_values(array_unique(array_filter(array_map(
            fn ($b) => in_array($b, ['own', 'category', 'subcategory', 'platform', 'fallback', 'measured', 'default', 'none'], true) ? __("growth.basis.$b") : null,
            array_values($p['basis'] ?? [])
        ))));
        if (!empty($p['learned'])) $basis[] = __('growth.basis.learned');

        return [
            'id'             => (int) $row->id,
            'type'           => $type,
            'status'         => $row->status,
            'confidence'     => $row->confidence,
            'impact'         => $row->impact_high !== null ? ['low' => (int) $row->impact_low, 'high' => (int) $row->impact_high] : null,
            'headline'       => $ai ?: $headline,
            'headline_plain' => $headline,
            'recommendation' => $recommendation,
            'basis'          => $basis,
            'evidence'       => $p['evidence'] ?? [],
            'action'         => $p['action'] ?? null,
            'alt'            => $p['alt'] ?? null,
            'product'        => $row->product_id ? ($this->products[$row->product_id] ?? null) : null,
            'params'         => $p['params'] ?? [],
            // Full funnel diagnosis lives in Analyse des visiteurs (Black Pepper)
            'insights_href'  => !empty($p['evidence']['insights']) && $row->product_id
                ? "/seller/black/visitor-insights?product={$row->product_id}" : null,
            'snoozed_until'  => $row->snoozed_until,
            'created_at'     => CarbonImmutable::parse($row->created_at)->toIso8601String(),
        ];
    }

    /** @return array{0: string, 1: string} */
    public function texts(string $type, array $params, array $action): array
    {
        $k = "growth.cards.$type";
        return match ($type) {
            'leaking_product' => [trans_choice("$k.headline", (int) ($params['orders'] ?? 0), $params), __("$k.recommendation." . ($params['focus'] ?? 'price_test'), $params)],
            'price_position'  => [__("$k.headline." . ($params['direction'] ?? 'high'), $params), __("$k.recommendation." . ($params['direction'] ?? 'high'), $params)],
            'seasonal'        => [__("$k.headline", $params), __("$k.recommendation." . (($action['kind'] ?? 'discount') === 'flash_sale' ? 'flash_sale' : 'discount'), $params)],
            'dead_stock'      => [__("$k.headline", $params), __("$k.recommendation." . ($params['focus'] ?? 'clearance'), $params)],
            default           => [__("$k.headline", $params), __("$k.recommendation", $params)],
        };
    }

    /** Localized params: day names, dates, event names, money without trailing zeros. */
    public function params(array $p, string $locale): array
    {
        if (isset($p['event_names'])) $p['event'] = $p['event_names'][$locale] ?? $p['event_names']['fr'] ?? ($p['event'] ?? '');
        if (isset($p['day'])) $p['day'] = CarbonImmutable::now()->startOfWeek()->addDays((int) $p['day'] - 1)->locale($locale)->dayName;
        foreach (['start_date', 'starts_on'] as $d) {
            if (!empty($p[$d])) $p[$d] = CarbonImmutable::parse($p[$d])->locale($locale)->translatedFormat('j F');
        }
        foreach (['price', 'median', 'new_price'] as $m) {
            if (isset($p[$m])) $p[$m] = self::money((float) $p[$m]);
        }
        if (isset($p['hour'])) $p['hour'] = str_pad((string) $p['hour'], 2, '0', STR_PAD_LEFT);
        return array_filter($p, fn ($v) => is_scalar($v));
    }

    public static function money(float $v): string
    {
        return abs($v - round($v)) < 0.05 ? (string) (int) round($v) : number_format($v, 1, '.', '');
    }

    private function loadProducts(array $ids): void
    {
        $ids = array_values(array_diff(array_unique($ids), array_keys($this->products)));
        if (!$ids) return;
        $rows = DB::table('products as p')
            ->leftJoin('product_images as pi', fn ($j) => $j->on('pi.product_id', '=', 'p.id')->where('pi.is_primary', true)->whereNull('pi.variant_id'))
            ->whereIn('p.id', $ids)
            ->groupBy('p.id', 'p.name', 'p.slug', 'p.price', 'p.stock')
            ->selectRaw('p.id, p.name, p.slug, p.price, p.stock, MIN(pi.image_path) as image')->get();
        foreach ($rows as $r) {
            $this->products[(int) $r->id] = [
                'id' => (int) $r->id, 'name' => $r->name, 'slug' => $r->slug, 'price' => (float) $r->price, 'stock' => (int) $r->stock,
                'image' => $r->image ? url(Storage::url($r->image)) : null,
            ];
        }
    }
}

<?php

namespace App\Services\GrowthRadar;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Applied actions and their measured results, shaped for the dashboard. */
class Results
{
    public function __construct(private Presenter $presenter) {}

    /** Result cards for the feed: measured in the last config('growth.results.show_days') days. */
    public function recent(int $sellerId, string $locale): array
    {
        $rows = DB::table('growth_actions')->where('seller_id', $sellerId)->where('status', 'measured')
            ->where('measured_at', '>=', now()->subDays((int) config('growth.results.show_days')))
            ->orderByDesc('measured_at')->limit(3)->get()->all();
        return $this->present($rows, $locale);
    }

    public function history(int $sellerId, string $locale): array
    {
        $rows = DB::table('growth_actions')->where('seller_id', $sellerId)
            ->orderByDesc('starts_at')->limit(100)->get()->all();
        return $this->present($rows, $locale);
    }

    private function present(array $rows, string $locale): array
    {
        $names = DB::table('products')->whereIn('id', array_filter(array_map(fn ($r) => $r->product_id, $rows)))
            ->pluck('name', 'id');
        return array_map(function ($r) use ($names, $locale) {
            $res = $r->result ? json_decode($r->result, true) : null;
            $product = $names[$r->product_id] ?? null;
            $kind = __('growth.kinds.' . $r->kind);
            return [
                'id'        => (int) $r->id,
                'kind'      => $r->kind,
                'kind_label'=> $kind,
                'card_type' => $r->card_type,
                'product'   => $r->product_id ? ['id' => (int) $r->product_id, 'name' => $product] : null,
                'starts_at' => CarbonImmutable::parse($r->starts_at, 'UTC')->toIso8601String(),
                'ends_at'   => CarbonImmutable::parse($r->ends_at, 'UTC')->toIso8601String(),
                'status'    => $r->status,
                'verdict'   => $r->verdict,
                'headline'  => $r->verdict ? __("growth.cards.results.headline.{$r->verdict}", ['kind' => $kind, 'product' => $product ?? '']) : null,
                'result'    => $res,
                'unclear_reasons' => array_map(fn ($k) => __("growth.unclear.$k"), $res['unclear'] ?? []),
                'measure_on'=> CarbonImmutable::parse($r->ends_at, 'UTC')->addDays((int) config('growth.results.after_days'))->toDateString(),
            ];
        }, $rows);
    }
}

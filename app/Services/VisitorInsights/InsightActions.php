<?php

namespace App\Services\VisitorInsights;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Actions a seller applied from Analyse des visiteurs, and their funnel impact.
 *
 * Stored in growth_actions with origin = visitor_insights (Growth Radar's own
 * measurement ignores them). Recorded when the action is really applied:
 *  - discount / flash sale / coupon / boost: by the existing endpoints, which accept
 *    `insight_product` + `insight_problem` (+ `insight_stage`) like they accept growth_card_id;
 *  - listing edit / restock: by the dashboard after the save (POST …/visitor-insights/actions).
 *
 * Impact = the product's funnel rates over the window_days before the action vs the
 * days after it (from product_daily_stats), shown once min_after_days have passed
 * and both sides have min_views.
 */
class InsightActions
{
    public const ORIGIN = 'visitor_insights';
    public const KINDS = ['edit', 'restock', 'discount', 'flash_sale', 'coupon', 'boost'];

    public function record(int $sellerId, int $productId, string $kind, ?string $problem, ?string $stage,
                           ?int $refId = null, ?CarbonInterface $startsAt = null, ?CarbonInterface $endsAt = null): ?int
    {
        if (!in_array($kind, self::KINDS, true)) return null;
        if (!DB::table('products')->where('id', $productId)->where('seller_id', $sellerId)->exists()) return null;

        $startsAt ??= now();
        $endsAt ??= $startsAt;
        // Saving the same listing twice in a day is one action
        $existing = DB::table('growth_actions')->where('seller_id', $sellerId)->where('origin', self::ORIGIN)
            ->where('product_id', $productId)->where('kind', $kind)->whereNull('ref_id')
            ->where('created_at', '>=', now()->startOfDay())->value('id');
        if ($existing && !$refId) return (int) $existing;

        $id = DB::table('growth_actions')->insertGetId([
            'seller_id'    => $sellerId,
            'origin'       => self::ORIGIN,
            'card_id'      => null,
            'card_type'    => null,
            'stage'        => in_array($stage, FunnelDiagnosis::STAGES, true) ? $stage : null,
            'problem_code' => $problem ? mb_substr(preg_replace('/[^a-z_]/', '', $problem), 0, 32) : null,
            'product_id'   => $productId,
            'kind'         => $kind,
            'ref_id'       => $refId,
            'starts_at'    => $startsAt->copy()->utc(),
            'ends_at'      => $endsAt->copy()->utc(),
            'status'       => 'running',
            'created_at'   => now(), 'updated_at' => now(),
        ]);
        VisitorInsights::forget($sellerId);
        return $id;
    }

    /** From a promotion / coupon / boost request that carries insight_product (+ insight_problem). */
    public function recordFromRequest(Request $request, string $kind, ?int $refId, array $productIds,
                                      ?CarbonInterface $startsAt = null, ?CarbonInterface $endsAt = null): ?int
    {
        $pid = (int) $request->input('insight_product');
        if (!$pid || !in_array($pid, array_map('intval', $productIds), true)) return null;
        return $this->record((int) $request->user()->id, $pid, $kind,
            $request->input('insight_problem'), $request->input('insight_stage'), $refId, $startsAt, $endsAt);
    }

    /** Applied actions with their before / after funnel. */
    public function results(int $sellerId, int $limit = 12): array
    {
        $cfg = config('funnel.impact');
        $tz = config('funnel.timezone');
        $yesterday = CarbonImmutable::now($tz)->subDay()->startOfDay();

        $rows = DB::table('growth_actions as a')
            ->leftJoin('products as p', 'p.id', '=', 'a.product_id')
            ->leftJoin('product_images as pi', fn ($j) => $j->on('pi.product_id', '=', 'p.id')->where('pi.is_primary', true)->whereNull('pi.variant_id'))
            ->where('a.seller_id', $sellerId)->where('a.origin', self::ORIGIN)
            ->groupBy('a.id', 'a.product_id', 'a.kind', 'a.problem_code', 'a.stage', 'a.starts_at', 'p.name')
            ->orderByDesc('a.starts_at')->limit($limit)
            ->selectRaw('a.id, a.product_id, a.kind, a.problem_code, a.stage, a.starts_at, p.name, MIN(pi.image_path) as image')
            ->get();

        return $rows->map(function ($a) use ($cfg, $tz, $yesterday) {
            $day = CarbonImmutable::parse($a->starts_at, 'UTC')->tz($tz)->startOfDay();
            $w = (int) $cfg['window_days'];
            $before = $this->window((int) $a->product_id, $day->subDays($w), $day->subDay());
            $afterEnd = $day->addDays($w - 1)->min($yesterday);
            $afterDays = $afterEnd->lt($day) ? 0 : (int) $day->diffInDays($afterEnd) + 1;
            $after = $afterDays > 0 ? $this->window((int) $a->product_id, $day, $afterEnd) : null;

            $status = 'measured';
            if ($afterDays < (int) $cfg['min_after_days']) $status = 'measuring';
            elseif ($before['views'] < $cfg['min_views'] || ($after['views'] ?? 0) < $cfg['min_views']) $status = 'insufficient';

            $metrics = [];
            if ($status === 'measured') {
                foreach (['ctr', 'view_to_cart', 'cart_to_order', 'conversion'] as $k) {
                    if ($before['rates'][$k] === null && $after['rates'][$k] === null) continue;
                    $metrics[] = ['metric' => $k, 'before' => $before['rates'][$k], 'after' => $after['rates'][$k]];
                }
                $metrics[] = ['metric' => 'views_per_day', 'before' => round($before['views'] / $w, 1), 'after' => round($after['views'] / $afterDays, 1)];
            }
            return [
                'id'           => (int) $a->id,
                'product'      => ['id' => (int) $a->product_id, 'name' => $a->name,
                                   'image' => $a->image ? url(\Illuminate\Support\Facades\Storage::url($a->image)) : null],
                'kind'         => $a->kind,
                'problem_code' => $a->problem_code,
                'stage'        => $a->stage,
                'applied_on'   => $day->format('Y-m-d'),
                'status'       => $status,
                'days_after'   => $afterDays,
                'days_needed'  => (int) $cfg['min_after_days'],
                'metrics'      => $metrics,
            ];
        })->all();
    }

    private function window(int $productId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $s = DB::table('product_daily_stats')->where('product_id', $productId)
            ->whereBetween('date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->selectRaw('COALESCE(SUM(impressions),0) as impressions, COALESCE(SUM(clicks),0) as clicks, COALESCE(SUM(views),0) as views,
                         COALESCE(SUM(add_to_cart),0) as carts, COALESCE(SUM(orders),0) as orders')->first();
        $m = ['impressions' => (int) $s->impressions ?: null, 'clicks' => (int) $s->clicks, 'views' => (int) $s->views,
              'carts' => (int) $s->carts, 'orders' => (int) $s->orders];
        return ['views' => $m['views'], 'rates' => FunnelDiagnosis::rates($m)];
    }
}

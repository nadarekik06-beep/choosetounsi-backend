<?php

namespace App\Services\Forecast;

use App\Services\PlanGate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Computes, stores and serves a seller's forecasts.
 *
 * computeSeller() rebuilds the seller's daily series, forecasts every product
 * (+ variants) and the whole shop, and writes one snapshot per product /
 * variant / shop for today. The dashboard only ever reads snapshots.
 */
class ForecastService
{
    public function __construct(
        private SalesSeries $series,
        private CategoryPrior $priors,
        private Calendar $calendar,
        private ActionBuilder $actions,
        private ShopAggregator $shop,
    ) {}

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('forecast.timezone'))->startOfDay();
    }

    /** @return array{products: array<int, array>, shop: array} */
    public function computeSeller(int $sellerId, ?CarbonImmutable $today = null): array
    {
        $today = $today ?? $this->today();
        $this->series->rebuild($sellerId, $today);
        $products = $this->series->load($sellerId);
        $settings = $this->settings($sellerId);
        $forecaster = new ProductForecaster();

        $previous  = $this->previousSnapshots($sellerId, $today);
        $promos    = $this->scheduledPromos($sellerId, $today);
        $results   = [];
        $shopItems = [];
        $allActions = [];

        foreach ($products as $pid => $p) {
            $lead = $settings['products'][$pid]['lead_time_days'] ?? $settings['lead_time_days'];
            $safe = $settings['products'][$pid]['safety_days'] ?? $settings['safety_days'];

            $result = $forecaster->forecast([
                'today'          => $today->toDateString(),
                'listed_since'   => $p['listed_since'],
                'daily'          => $p['daily'],
                'variants'       => $p['variants'],
                'stock'          => $p['stock'],
                'price'          => $p['price'],
                'prior'          => $this->priors->for($p['category_id'], $p['price'], $sellerId, $today),
                'events'         => $this->calendar->forCategory($p['category_id'], $today),
                'promos'         => $promos[$pid] ?? [],
                'lead_time_days' => $lead,
                'safety_days'    => $safe,
            ]);

            $result['scope']        = 'product';
            $result['product_id']   = $pid;
            $result['product_name'] = $p['name'];
            $result['live']         = $p['live'];
            $result['accuracy']     = $this->accuracy($previous['matured'][$pid] ?? null, $p['daily'], $today);
            $drop = $this->dropCheck($previous['recent'][$pid] ?? null, $p['daily'], $today);
            $result['actions']      = $p['live'] ? $this->actions->forProduct($p, $result, $today, $drop) : [];

            $results[$pid] = $result;
            if ($p['live']) {
                $shopItems[]  = ['product' => $p, 'result' => $result];
                $allActions[] = $result['actions'];
            }
        }

        $shop = $this->shop->aggregate($shopItems, $this->actions->forShop($allActions));
        $shopDaily = $this->shopDaily($products);
        $shop['accuracy'] = $this->accuracy($previous['matured'][0] ?? null, $shopDaily, $today);

        $this->store($sellerId, $today, $results, $shop, $previous);

        return ['products' => $results, 'shop' => $shop];
    }

    // ═════════════════════════════════════════════════════════════════════
    // Serving
    // ═════════════════════════════════════════════════════════════════════

    /** Latest snapshot payload for a product (0 = whole shop), or null. */
    public function latest(int $sellerId, int $productId = 0): ?array
    {
        $row = DB::table('forecast_snapshots')
            ->where('seller_id', $sellerId)->where('product_id', $productId)->where('variant_id', 0)
            ->orderByDesc('snapshot_date')->first();
        if (!$row) return null;
        $payload = json_decode($row->payload, true);
        if (!isset($payload['tier'], $payload['history'])) return null;   // compacted
        $payload['snapshot_date'] = (string) $row->snapshot_date;
        $payload['computed_at']   = (string) $row->updated_at;
        return $payload;
    }

    /** Per-product summary rows for the product selector. */
    public function productList(int $sellerId): array
    {
        $date = DB::table('forecast_snapshots')->where('seller_id', $sellerId)->max('snapshot_date');
        if (!$date) return [];
        return DB::table('forecast_snapshots as fs')
            ->join('products as p', 'p.id', '=', 'fs.product_id')
            ->where('fs.seller_id', $sellerId)->where('fs.snapshot_date', $date)
            ->where('fs.variant_id', 0)->where('fs.product_id', '>', 0)
            ->whereNull('p.deleted_at')
            ->orderBy('p.name')
            ->get(['fs.product_id', 'p.name', 'fs.tier', 'fs.confidence', 'fs.payload'])
            ->map(function ($r) {
                $p = json_decode($r->payload, true);
                $daysLeft = $p['stock']['days_left'] ?? null;
                foreach ($p['variants'] ?? [] as $v) {
                    if ($v['stock']['days_left'] !== null) $daysLeft = $daysLeft === null ? $v['stock']['days_left'] : min($daysLeft, $v['stock']['days_left']);
                }
                return [
                    'id' => (int) $r->product_id, 'name' => $r->name, 'tier' => $r->tier,
                    'confidence' => (int) $r->confidence, 'days_left' => $daysLeft,
                    'has_variants' => !empty($p['variants']), 'live' => $p['live'] ?? true,
                    'actions' => count($p['actions'] ?? []),
                ];
            })->values()->all();
    }

    /** Track record over the last 6 months: matured product forecasts vs what sold. */
    public function accuracySummary(int $sellerId): array
    {
        $rows = DB::table('forecast_snapshots')
            ->where('seller_id', $sellerId)->where('product_id', '>', 0)->where('variant_id', 0)
            ->whereNotNull('actual_28')->whereNotNull('h28_low')
            ->where('snapshot_date', '>=', $this->today()->subDays(180)->toDateString())
            ->get(['product_id', 'h28_point', 'h28_low', 'h28_high', 'actual_28']);
        if ($rows->isEmpty()) return ['forecasts' => 0];

        $within = $rows->filter(fn($r) => $r->actual_28 >= $r->h28_low && $r->actual_28 <= $r->h28_high)->count();
        $actual = (int) $rows->sum('actual_28');
        $absErr = $rows->sum(fn($r) => abs($r->h28_point - $r->actual_28));
        return [
            'forecasts'    => $rows->count(),
            'products'     => $rows->pluck('product_id')->unique()->count(),
            'coverage_pct' => (int) round(100 * $within / $rows->count()),
            // Weighted absolute % error — MAPE is undefined for products that sold 0.
            'wape_pct'     => $actual > 0 ? (int) round(100 * $absErr / $actual) : null,
            'mae_units'    => round($absErr / $rows->count(), 1),
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    // Settings
    // ═════════════════════════════════════════════════════════════════════

    public function settings(int $sellerId): array
    {
        $rows = DB::table('forecast_settings')->where('seller_id', $sellerId)->get();
        $shop = $rows->firstWhere('product_id', null);
        $out = [
            'lead_time_days'      => (int) ($shop->lead_time_days ?? config('forecast.default_lead_time_days')),
            'safety_days'         => (int) ($shop->safety_days ?? config('forecast.default_safety_days')),
            'alerts_enabled'      => (bool) ($shop->alerts_enabled ?? true),
            'alerts_email'        => (bool) ($shop->alerts_email ?? true),
            'weekly_digest'       => (bool) ($shop->weekly_digest ?? false),
            'stockout_alert_days' => (int) ($shop->stockout_alert_days ?? 14),
            'products'            => [],
        ];
        foreach ($rows->whereNotNull('product_id') as $r) {
            $out['products'][(int) $r->product_id] = array_filter([
                'lead_time_days' => $r->lead_time_days, 'safety_days' => $r->safety_days,
            ], fn($v) => $v !== null);
        }
        return $out;
    }

    public function saveSettings(int $sellerId, array $data, ?int $productId = null): void
    {
        $values = array_intersect_key($data, array_flip(
            $productId ? ['lead_time_days', 'safety_days']
                       : ['lead_time_days', 'safety_days', 'alerts_enabled', 'alerts_email', 'weekly_digest', 'stockout_alert_days']
        ));
        DB::table('forecast_settings')->updateOrInsert(
            ['seller_id' => $sellerId, 'product_id' => $productId],
            $values + ['updated_at' => now(), 'created_at' => now()]
        );
    }

    /** Sellers whose plan includes the forecast (analytics feature). */
    public function eligibleSellerIds(): array
    {
        $gate = app(PlanGate::class);
        return DB::table('seller_applications')->where('status', 'approved')->pluck('user_id')
            ->filter(fn($id) => $gate->feature((int) $id, 'analytics') === null)
            ->map(fn($id) => (int) $id)->values()->all();
    }

    // ═════════════════════════════════════════════════════════════════════
    // Internals
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Snapshots the accuracy and drop checks need: the latest one made 28–42
     * days ago ("matured": its 4-week window is over) and the latest one made
     * 14–20 days ago. Keyed by product id (0 = shop).
     */
    private function previousSnapshots(int $sellerId, CarbonImmutable $today): array
    {
        $pick = function (int $fromDays, int $toDays) use ($sellerId, $today) {
            $rows = DB::table('forecast_snapshots')
                ->where('seller_id', $sellerId)->where('variant_id', 0)
                ->whereBetween('snapshot_date', [$today->subDays($toDays)->toDateString(), $today->subDays($fromDays)->toDateString()])
                ->orderBy('snapshot_date')
                ->get(['id', 'product_id', 'snapshot_date', 'h28_point', 'h28_low', 'h28_high', 'payload']);
            $out = [];
            foreach ($rows as $r) $out[(int) $r->product_id] = $r;   // latest wins
            return $out;
        };
        return ['matured' => $pick(28, 42), 'recent' => $pick(14, 20)];
    }

    /** "Précision passée": what we forecast ~4 weeks ago vs what actually sold. */
    private function accuracy(?object $snap, array $daily, CarbonImmutable $today): ?array
    {
        if (!$snap || $snap->h28_low === null) return null;
        $start  = CarbonImmutable::parse($snap->snapshot_date);
        $actual = 0;
        for ($d = 0; $d < 28; $d++) $actual += (int) ($daily[$start->addDays($d)->toDateString()]['units'] ?? 0);
        return [
            'snapshot_id'   => (int) $snap->id,
            'snapshot_date' => $start->toDateString(),
            'point'         => (int) round((float) $snap->h28_point),
            'low'           => (int) $snap->h28_low,
            'high'          => (int) $snap->h28_high,
            'actual'        => $actual,
            'within'        => $actual >= $snap->h28_low && $actual <= $snap->h28_high,
        ];
    }

    /** Units sold in the 14 days after a forecast made 14–20 days ago vs its range. */
    private function dropCheck(?object $snap, array $daily, CarbonImmutable $today): ?array
    {
        if (!$snap) return null;
        $dist = json_decode($snap->payload, true)['dist']['weeks'] ?? null;
        if (!$dist || count($dist) < 2) return null;
        $mean = $dist[0][0] + $dist[1][0];
        $var  = $dist[0][1] + $dist[1][1];
        $start  = CarbonImmutable::parse($snap->snapshot_date);
        $actual = 0;
        for ($d = 0; $d < 14; $d++) $actual += (int) ($daily[$start->addDays($d)->toDateString()]['units'] ?? 0);
        return [
            'since'          => $start->toDateString(),
            'actual'         => $actual,
            'expected_point' => (int) round($mean),
            'expected_low'   => Stats::quantile($mean, $var, 0.1),
        ];
    }

    /** Future windows of this seller's flash sales / discounts, by product. */
    private function scheduledPromos(int $sellerId, CarbonImmutable $today): array
    {
        $tz = config('forecast.timezone');
        $rows = DB::table('promotions as pr')
            ->join('promotion_products as pp', 'pp.promotion_id', '=', 'pr.id')
            ->where('pr.seller_id', $sellerId)
            ->whereIn('pr.status', ['scheduled', 'active'])
            ->where('pr.ends_at', '>=', $today->utc())
            ->get(['pp.product_id', 'pr.starts_at', 'pr.ends_at']);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->product_id][] = [
                'starts_on' => CarbonImmutable::parse($r->starts_at, 'UTC')->tz($tz)->toDateString(),
                'ends_on'   => CarbonImmutable::parse($r->ends_at, 'UTC')->tz($tz)->toDateString(),
            ];
        }
        return $out;
    }

    private function shopDaily(array $products): array
    {
        $out = [];
        foreach ($products as $p) {
            if (!$p['live']) continue;
            foreach ($p['daily'] as $day => $r) {
                $out[$day]['units'] = ($out[$day]['units'] ?? 0) + $r['units'];
            }
        }
        return $out;
    }

    private function store(int $sellerId, CarbonImmutable $today, array $results, array $shop, array $previous): void
    {
        $date = $today->toDateString();
        $now  = now();
        $rows = [];

        $row = fn(int $pid, int $vid, array $r, ?array $n28, string $payload) => [
            'seller_id' => $sellerId, 'product_id' => $pid, 'variant_id' => $vid, 'snapshot_date' => $date,
            'tier' => $r['tier'], 'model' => $r['model'] ?? null, 'confidence' => $r['confidence']['score'] ?? 0,
            'h28_point' => $n28['mean'] ?? ($n28['point'] ?? null), 'h28_low' => $n28['low'] ?? null, 'h28_high' => $n28['high'] ?? null,
            'payload' => $payload, 'created_at' => $now, 'updated_at' => $now,
        ];

        foreach ($results as $pid => $r) {
            $rows[] = $row($pid, 0, $r, $r['next28'], json_encode($r, JSON_UNESCAPED_UNICODE));
            foreach ($r['variants'] as $v) {
                $rows[] = $row($pid, $v['variant_id'], $r, $v['next28'], json_encode(['tier' => $r['tier']]));
            }
        }
        $rows[] = $row(0, 0, $shop, $shop['next28'], json_encode($shop, JSON_UNESCAPED_UNICODE));

        DB::transaction(function () use ($sellerId, $date, $rows, $results, $shop) {
            DB::table('forecast_snapshots')->where('seller_id', $sellerId)->where('snapshot_date', $date)->delete();
            foreach (array_chunk($rows, 200) as $chunk) DB::table('forecast_snapshots')->insert($chunk);

            // Record outcomes of matured forecasts (feeds accuracySummary)
            $matured = array_filter(array_merge(array_column($results, 'accuracy'), [$shop['accuracy'] ?? null]));
            foreach ($matured as $acc) {
                DB::table('forecast_snapshots')->where('id', $acc['snapshot_id'])
                    ->update(['actual_28' => $acc['actual'], 'matured_at' => now()]);
            }
        });
    }

    /**
     * Shrink payloads older than a week to what accuracy / drop checks need.
     * The summary columns (h28_*, actual_28) are kept forever.
     */
    public static function compactOldSnapshots(CarbonImmutable $today): int
    {
        $n = 0;
        DB::table('forecast_snapshots')
            ->where('snapshot_date', '<', $today->subDays(7)->toDateString())
            ->whereRaw('LENGTH(payload) > 600')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$n) {
                foreach ($rows as $r) {
                    $p = json_decode($r->payload, true) ?: [];
                    DB::table('forecast_snapshots')->where('id', $r->id)->update([
                        'payload' => json_encode(['tier' => $p['tier'] ?? null, 'dist' => $p['dist'] ?? null]),
                    ]);
                    $n++;
                }
            });
        return $n;
    }
}

<?php

namespace Tests\Unit\Forecast;

use App\Services\Forecast\AiNarrator;
use App\Services\Forecast\ProductForecaster;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Forecaster scenarios on synthetic histories (no database).
 * Run: php vendor/bin/phpunit tests/Unit/Forecast
 */
class ProductForecasterTest extends TestCase
{
    private const TODAY = '2026-10-02';

    private function input(array $over = []): array
    {
        return $over + [
            'today' => self::TODAY, 'listed_since' => '2026-01-01', 'daily' => [], 'variants' => [],
            'stock' => 50, 'price' => 40.0, 'prior' => null, 'events' => [], 'promos' => [],
            'lead_time_days' => 7, 'safety_days' => 7,
        ];
    }

    /** Deterministic daily series: $fn(int $daysAgo, CarbonImmutable $day) → units */
    private function daily(int $days, callable $fn, array $promoDaysAgo = []): array
    {
        $t = CarbonImmutable::parse(self::TODAY);
        $out = [];
        for ($i = $days; $i >= 1; $i--) {
            $d = $t->subDays($i);
            $u = (int) $fn($i, $d);
            $promo = in_array($i, $promoDaysAgo, true);
            if ($u > 0 || $promo) {
                $out[$d->toDateString()] = ['units' => $u, 'net_revenue' => $u * 40.0, 'orders' => $u,
                    'promo_units' => $promo ? $u : 0, 'promo_day' => $promo, 'views' => 0, 'cart_adds' => 0, 'favorites' => 0];
            }
        }
        return $out;
    }

    private function prior(): array
    {
        return ['rates' => [0, 0, 0.05, 0.1, 0.2, 0.15, 0.02, 0.08], 'products' => 8, 'sellers' => 4, 'orders' => 60,
                'scope' => 'price_band', 'cart_rate' => 0.06];
    }

    private function forecast(array $in): array
    {
        return (new ProductForecaster())->forecast($this->input($in));
    }

    // ── Cold start ──────────────────────────────────────────────────────────

    public function test_zero_history_without_category_data_shows_no_numbers(): void
    {
        $r = $this->forecast(['listed_since' => '2026-09-20']);
        $this->assertSame('insufficient', $r['tier']);
        $this->assertNull($r['next28']);
        $this->assertSame([], $r['weeks']);
        $this->assertSame([], $r['months']);
        $this->assertSame(0, $r['confidence']['score']);
        $this->assertNull($r['stock']['days_left']);
        $this->assertSame(0, $r['stock']['reorder_qty']);
    }

    public function test_zero_history_with_category_data_gives_a_labelled_range(): void
    {
        $r = $this->forecast(['listed_since' => '2026-09-25', 'prior' => $this->prior()]);
        $this->assertSame('category', $r['tier']);
        $this->assertSame('category_prior', $r['model']);
        $this->assertLessThan($r['next28']['high'], $r['next28']['low'] + 1);
        $this->assertGreaterThan($r['next28']['low'], $r['next28']['high']);  // a range, never a single number
        $this->assertLessThanOrEqual(20, $r['confidence']['score']);
        $this->assertSame('low', $r['confidence']['level']);
    }

    public function test_seller_weight_grows_as_their_own_data_accumulates(): void
    {
        $short = $this->forecast(['listed_since' => '2026-09-10', 'prior' => $this->prior(),
                             'daily' => $this->daily(20, fn($i) => $i === 5 ? 1 : 0)]);
        $long  = $this->forecast(['listed_since' => '2026-05-01', 'prior' => $this->prior(),
                             'daily' => $this->daily(150, fn($i) => in_array($i, [5, 40, 90], true) ? 1 : 0)]);
        $this->assertSame('blend', $short['tier']);
        $this->assertSame('blend', $long['tier']);
        $this->assertGreaterThan(0, $short['weight_own']);
        $this->assertLessThan(1, $short['weight_own']);
        $this->assertGreaterThan($short['weight_own'], $long['weight_own']);
        $this->assertLessThanOrEqual(45, $long['confidence']['score']);
    }

    // ── Models ──────────────────────────────────────────────────────────────

    public function test_intermittent_demand_uses_croston_family_and_honest_range(): void
    {
        // A sale on every 9th day for 200 days: ~22 orders, many zero weeks
        $r = $this->forecast(['listed_since' => '2026-03-01', 'daily' => $this->daily(200, fn($i) => $i % 9 === 0 ? 1 : 0)]);
        $this->assertSame('own', $r['tier']);
        $this->assertTrue($r['data']['intermittent']);
        $this->assertContains($r['model'], ['sba', 'croston', 'mean']);
        // True rate 28/9 ≈ 3.1 units per 4 weeks
        $this->assertEqualsWithDelta(3.1, $r['next28']['mean'], 1.2);
        $this->assertLessThanOrEqual(3, $r['next28']['low']);
        $this->assertGreaterThanOrEqual(4, $r['next28']['high']);
        $this->assertNotNull($r['confidence']['error_pct']);
    }

    public function test_seasonal_product_forecasts_its_season(): void
    {
        // Winter product: ~3/day in January, ~0.5/day in July, for 2.3 years
        $fn = fn($i, CarbonImmutable $d) => (int) round(1.75 + 1.25 * cos(2 * M_PI * ((int) $d->format('z') - 15) / 365.25));
        $r = $this->forecast(['listed_since' => '2024-06-01', 'daily' => $this->daily(850, $fn), 'stock' => 500]);
        $this->assertSame('own', $r['tier']);
        $this->assertContains($r['model'], ['seasonal_naive', 'holt_winters']);
        $months = collect($r['months'])->keyBy('month');
        $this->assertGreaterThan($months['2026-11']['point'], $months['2027-01']['point']);
        $this->assertGreaterThan($months['2027-04']['point'], $months['2027-01']['point']);
    }

    public function test_promo_spike_does_not_inflate_the_baseline(): void
    {
        // Steady 1/day, with a 7-day flash sale 30–36 days ago selling 10/day
        $promoDays = range(30, 36);
        $fn = fn($i) => in_array($i, $promoDays, true) ? 10 : 1;
        $flagged   = $this->forecast(['listed_since' => '2026-04-01', 'daily' => $this->daily(180, $fn, $promoDays)]);
        $unflagged = $this->forecast(['listed_since' => '2026-04-01', 'daily' => $this->daily(180, $fn)]);

        $this->assertEqualsWithDelta(28, $flagged['next28']['mean'], 4);
        $this->assertTrue($flagged['data']['promo_excluded']);
        $this->assertNotNull($flagged['promo_uplift']);
        $this->assertGreaterThan(5, $flagged['promo_uplift']['factor']);
        $this->assertSame(7, $flagged['promo_uplift']['promo_days']);
        // Without the promo marker the spike leaks into the baseline
        $this->assertGreaterThanOrEqual($flagged['next28']['mean'], $unflagged['next28']['mean']);
    }

    // ── Stock ───────────────────────────────────────────────────────────────

    public function test_days_of_stock_and_reorder_recommendation(): void
    {
        $r = $this->forecast(['listed_since' => '2026-04-01', 'stock' => 20, 'daily' => $this->daily(180, fn() => 2)]);
        $this->assertSame('own', $r['tier']);
        // ~2/day → 20 units last ~10 days
        $this->assertEqualsWithDelta(10, $r['stock']['days_left'], 2);
        $this->assertSame(CarbonImmutable::parse(self::TODAY)->addDays($r['stock']['days_left'])->toDateString(), $r['stock']['stockout_date']);
        // Reorder by = stock-out − lead (7) − safety (7) → already due today
        $this->assertSame(self::TODAY, $r['stock']['reorder_by']);
        // Covers lead time + 4 weeks at the high end + 7 safety days, minus stock: ≥ 2×(35+7) − 20
        $this->assertGreaterThanOrEqual(64, $r['stock']['reorder_qty']);
    }

    public function test_variants_get_their_own_stock_out_dates(): void
    {
        $t = CarbonImmutable::parse(self::TODAY);
        $vd = fn(int $every) => collect(range(1, 120))->filter(fn($i) => $i % $every === 0)
            ->mapWithKeys(fn($i) => [$t->subDays($i)->toDateString() => 1])->all();
        $r = $this->forecast([
            'listed_since' => '2026-05-01', 'stock' => 33,
            'daily' => $this->daily(120, fn($i) => ($i % 1 === 0 ? 1 : 0) + ($i % 3 === 0 ? 1 : 0)),
            'variants' => [
                11 => ['labels' => ['fr' => 'M', 'en' => 'M', 'ar' => 'M'], 'stock' => 3,  'daily' => $vd(1)],
                12 => ['labels' => ['fr' => 'L', 'en' => 'L', 'ar' => 'L'], 'stock' => 30, 'daily' => $vd(3)],
            ],
        ]);
        $this->assertCount(2, $r['variants']);
        $m = collect($r['variants'])->firstWhere('variant_id', 11);
        $l = collect($r['variants'])->firstWhere('variant_id', 12);
        $this->assertGreaterThan($l['share'], $m['share']);
        $this->assertLessThan($l['stock']['days_left'] ?? 999, $m['stock']['days_left']);
        $this->assertSame(11, $r['variants'][0]['variant_id']);   // soonest stock-out first
    }

    // ── Calendar ────────────────────────────────────────────────────────────

    public function test_event_effect_is_applied_only_when_measured_with_enough_orders(): void
    {
        $event = fn(int $orders) => [[
            'id' => 1, 'key' => 'aid_fitr', 'names' => ['fr' => 'Aïd', 'en' => 'Eid', 'ar' => 'عيد'],
            'starts_on' => '2026-10-09', 'ends_on' => '2026-10-15',
            'effect' => ['change_pct' => 100.0, 'event_orders' => $orders, 'baseline_orders' => 200, 'year' => 2025],
        ]];
        $base = ['listed_since' => '2026-04-01', 'daily' => $this->daily(180, fn() => 1), 'stock' => 999];
        $none     = $this->forecast($base);
        $weak     = $this->forecast($base + ['events' => $event(12)]);
        $reliable = $this->forecast($base + ['events' => $event(80)]);

        $this->assertEqualsWithDelta($none['weeks'][1]['mean'], $weak['weeks'][1]['mean'], 0.001);
        $this->assertFalse($weak['events'][0]['applied']);
        $this->assertTrue($reliable['events'][0]['applied']);
        $this->assertEqualsWithDelta($none['weeks'][1]['mean'] * 2, $reliable['weeks'][1]['mean'], 0.01);
        $this->assertSame(7, $reliable['events'][0]['days_until']);
    }

    // ── AI guard ────────────────────────────────────────────────────────────

    public function test_ai_text_with_invented_numbers_is_rejected(): void
    {
        $n = app(AiNarrator::class);
        $facts = ['product' => 'PS 5', 'next_4_weeks_units_low' => 8, 'next_4_weeks_units_high' => 20, 'stockout_date' => '2026-10-15'];
        $this->assertTrue($n->numbersAllowed('PS 5 : entre 8 et 20 unités sur 4 semaines, rupture le 15/10.', $facts));
        $this->assertFalse($n->numbersAllowed('Entre 8 et 20 unités, soit une hausse de 35 %.', $facts));
        $this->assertTrue($n->numbersAllowed('بين ٨ و٢٠ وحدة', $facts));
    }

    public function test_template_text_exists_in_every_language(): void
    {
        $n = app(AiNarrator::class);
        $f = ['product' => 'Robe', 'tier' => 'own', 'reliability' => 'medium', 'orders' => 12, 'days_of_history' => 90,
              'next_4_weeks_units_low' => 3, 'next_4_weeks_units_high' => 9,
              'next_4_weeks_revenue_low_tnd' => 120, 'next_4_weeks_revenue_high_tnd' => 360];
        foreach (['fr', 'en', 'ar'] as $l) {
            $text = $n->template($f, $l);
            $this->assertStringContainsString('3', $text);
            $this->assertStringContainsString('9', $text);
            $this->assertStringNotContainsString('forecast.ai', $text, "missing $l translation");
            $this->assertTrue($n->numbersAllowed($text, $f), $l);
        }
    }
}

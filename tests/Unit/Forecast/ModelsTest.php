<?php

namespace Tests\Unit\Forecast;

use App\Services\Forecast\HijriCalendar;
use App\Services\Forecast\Models;
use App\Services\Forecast\Stats;
use PHPUnit\Framework\TestCase;

/**
 * Pure math: count distributions, time-series models, model selection, Hijri dates.
 * Run: php vendor/bin/phpunit tests/Unit/Forecast
 */
class ModelsTest extends TestCase
{
    public function test_poisson_quantiles_match_known_values(): void
    {
        // Poisson(2): P(X≤0)=0.135, P(X≤3)=0.857, P(X≤4)=0.947
        $this->assertSame(0, Stats::quantile(2, 2, 0.10));
        $this->assertSame(4, Stats::quantile(2, 2, 0.90));
        $this->assertSame([0, 0], Stats::interval(0, 0));
    }

    public function test_overdispersion_widens_the_range(): void
    {
        [$pl, $ph] = Stats::interval(10, 10);
        [$nl, $nh] = Stats::interval(10, 40);
        $this->assertGreaterThan($ph - $pl, $nh - $nl);
        $this->assertLessThanOrEqual($pl, $nl);
        $this->assertGreaterThanOrEqual($ph, $nh);
    }

    public function test_large_means_use_a_sane_normal_range(): void
    {
        [$low, $high] = Stats::interval(1000, 1000);
        $this->assertEqualsWithDelta(1000 - 1.2816 * sqrt(1000), $low, 2);
        $this->assertEqualsWithDelta(1000 + 1.2816 * sqrt(1000), $high, 2);
    }

    public function test_constant_series_is_forecast_exactly_by_every_model(): void
    {
        $y = array_fill(0, 120, 5.0);
        // (SBA is left out: its bias correction shades smooth series down by design —
        // it is only a candidate for intermittent demand.)
        foreach (['mean', 'ses', 'holt', 'croston', 'seasonal_naive', 'holt_winters'] as $m) {
            $f = Models::forecast($m, $y, 4);
            foreach ($f as $v) $this->assertEqualsWithDelta(5.0, $v, 0.05, $m);
        }
    }

    public function test_sba_estimates_the_rate_of_intermittent_demand(): void
    {
        // 2 units every 4th week → 0.5 units/week
        $y = [];
        for ($i = 0; $i < 52; $i++) $y[] = $i % 4 === 3 ? 2.0 : 0.0;
        $this->assertTrue(Models::isIntermittent($y));
        $rate = Models::sba($y, 1)[0];
        $this->assertEqualsWithDelta(0.5, $rate, 0.06);
        // The SBA correction makes it slightly lower than plain Croston
        $this->assertLessThan(Models::sba($y, 1, false)[0], $rate);
    }

    public function test_intermittent_series_selects_a_croston_family_model(): void
    {
        $y = [];
        for ($i = 0; $i < 40; $i++) $y[] = in_array($i % 5, [1], true) ? 3.0 : 0.0;
        $sel = Models::select($y);
        $this->assertTrue($sel['intermittent']);
        $this->assertContains($sel['model'], ['sba', 'croston', 'mean']);
        $this->assertArrayNotHasKey('holt', $sel['candidates']);
    }

    public function test_seasonal_series_selects_a_seasonal_model_and_forecasts_the_peak(): void
    {
        // 2.5 years of weekly data peaking every 52 weeks
        $y = [];
        for ($i = 0; $i < 130; $i++) $y[] = 10 + 8 * cos(2 * M_PI * $i / 52);
        $sel = Models::select($y);
        $this->assertContains($sel['model'], ['seasonal_naive', 'holt_winters']);

        $f = Models::forecast($sel['model'], $y, 52);
        // Next peak is at week 156 → 26 weeks ahead; trough at week 130+... check shape
        $peakIdx = array_search(max($f), $f);
        $this->assertEqualsWithDelta(156 - 130 - 1, $peakIdx, 2);
        $this->assertGreaterThan(15, max($f));
        $this->assertLessThan(5, min($f));
    }

    public function test_selection_without_enough_history_has_no_backtest(): void
    {
        $sel = Models::select([1, 0, 2]);
        $this->assertNull($sel['mae']);
    }

    public function test_models_never_forecast_negative_sales(): void
    {
        $y = [20, 18, 15, 12, 9, 6, 3, 1, 0, 0];
        foreach (['ses', 'holt', 'mean'] as $m) {
            foreach (Models::forecast($m, $y, 10) as $v) $this->assertGreaterThanOrEqual(0, $v);
        }
    }

    public function test_hijri_dates_are_not_tied_to_gregorian_months(): void
    {
        $this->assertSame('2026-02-18', HijriCalendar::toGregorian(1447, 9, 1)->toDateString());   // Ramadan 1447
        $this->assertSame('2026-05-27', HijriCalendar::toGregorian(1447, 12, 10)->toDateString()); // Aïd el-Idha 1447
        $r27 = collect(HijriCalendar::eventsForGregorianYear(2027))->firstWhere('key', 'ramadan');
        $r30 = collect(HijriCalendar::eventsForGregorianYear(2030))->firstWhere('key', 'ramadan');
        $this->assertSame('2027-02-08', $r27['starts_on']);
        $this->assertStringStartsWith('2030-01', $r30['starts_on']);   // moves ~11 days a year
        $this->assertSame([1447, 9, 1], HijriCalendar::fromGregorian(new \DateTimeImmutable('2026-02-18')));
    }
}

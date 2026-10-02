<?php

namespace App\Services\Forecast;

/**
 * Deterministic forecasting models on a weekly unit series (oldest first).
 *
 * Each model is fn(array $y, int $h): float[] (h future weeks, never negative).
 * Smoothing parameters are fitted on a small grid by one-step-ahead squared
 * error, so the same input always gives the same output.
 */
final class Models
{
    public const SEASON = 52;

    /** Minimum training length per model (weeks). */
    public const MIN_TRAIN = [
        'mean'            => 4,
        'ses'             => 4,
        'sba'             => 4,
        'croston'         => 4,
        'holt'            => 8,
        'seasonal_naive'  => self::SEASON + 8,
        'holt_winters'    => self::SEASON * 2,
    ];

    /** Simplest first: on (near) ties the simpler model wins. */
    public const ORDER = ['mean', 'ses', 'sba', 'croston', 'holt', 'seasonal_naive', 'holt_winters'];

    public static function forecast(string $model, array $y, int $h): array
    {
        $f = match ($model) {
            'mean'           => self::movingAverage($y, $h),
            'ses'            => self::ses($y, $h),
            'sba'            => self::sba($y, $h),
            'croston'        => self::sba($y, $h, false),
            'holt'           => self::holtDamped($y, $h),
            'seasonal_naive' => self::seasonalNaive($y, $h),
            'holt_winters'   => self::holtWinters($y, $h),
        };
        return array_map(fn($v) => max(0.0, (float) $v), $f);
    }

    /** Mean of the last 8 weeks — the benchmark every other model must beat. */
    public static function movingAverage(array $y, int $h, int $window = 8): array
    {
        $tail = array_slice($y, -$window);
        return array_fill(0, $h, Stats::mean($tail));
    }

    /** Simple exponential smoothing (flat forecast). */
    public static function ses(array $y, int $h): array
    {
        $best = null;
        foreach ([0.05, 0.1, 0.2, 0.3, 0.5] as $a) {
            $l = Stats::mean(array_slice($y, 0, min(4, count($y))));
            $sse = 0.0;
            foreach ($y as $v) {
                $sse += ($v - $l) ** 2;
                $l = $a * $v + (1 - $a) * $l;
            }
            if ($best === null || $sse < $best[0] - 1e-9) $best = [$sse, $l];
        }
        return array_fill(0, $h, $best[1]);
    }

    /** Holt's linear method with a damped trend (φ = 0.9), so trends fade out. */
    public static function holtDamped(array $y, int $h, float $phi = 0.9): array
    {
        $n    = count($y);
        $best = null;
        foreach ([0.1, 0.2, 0.3, 0.5] as $a) {
            foreach ([0.05, 0.1, 0.2] as $b) {
                $l   = $y[0];
                $t   = $n > 1 ? ($y[min(3, $n - 1)] - $y[0]) / max(1, min(3, $n - 1)) : 0.0;
                $sse = 0.0;
                for ($i = 1; $i < $n; $i++) {
                    $pred = $l + $phi * $t;
                    $sse += ($y[$i] - $pred) ** 2;
                    $lNew = $a * $y[$i] + (1 - $a) * $pred;
                    $t    = $b * ($lNew - $l) + (1 - $b) * $phi * $t;
                    $l    = $lNew;
                }
                if ($best === null || $sse < $best[0] - 1e-9) $best = [$sse, $l, $t];
            }
        }
        [, $l, $t] = $best;
        $out = [];
        $damp = 0.0;
        for ($k = 1; $k <= $h; $k++) {
            $damp += $phi ** $k;
            $out[] = $l + $damp * $t;
        }
        return $out;
    }

    /**
     * Croston's method with the Syntetos–Boylan correction (SBA) — for
     * intermittent demand (many weeks with zero sales). Forecast is a demand
     * rate per week: size / interval × (1 − α/2).
     */
    public static function sba(array $y, int $h, bool $correct = true): array
    {
        $nonZero = array_filter($y, fn($v) => $v > 0);
        if (!$nonZero) return array_fill(0, $h, 0.0);

        $best = null;
        foreach ([0.05, 0.1, 0.2, 0.3] as $a) {
            $k = $correct ? 1 - $a / 2 : 1.0;
            $z = null; $p = null; $q = 1; $sse = 0.0;
            foreach ($y as $v) {
                if ($z !== null) {
                    $sse += ($v - $k * $z / $p) ** 2;
                }
                if ($v > 0) {
                    if ($z === null) { $z = $v; $p = $q; }
                    else { $z = $a * $v + (1 - $a) * $z; $p = $a * $q + (1 - $a) * $p; }
                    $q = 1;
                } else {
                    $q++;
                }
            }
            if ($best === null || $sse < $best[0] - 1e-9) $best = [$sse, $k * $z / $p];
        }
        return array_fill(0, $h, $best[1]);
    }

    /**
     * Same week last year, shifted by the change in level between the last
     * 8 weeks and the same 8 weeks a year earlier (additive, so a zero year-ago
     * level can't blow it up).
     */
    public static function seasonalNaive(array $y, int $h): array
    {
        $n     = count($y);
        $m     = self::SEASON;
        $shift = Stats::mean(array_slice($y, $n - 8, 8)) - Stats::mean(array_slice($y, $n - 8 - $m, 8));
        $out   = [];
        for ($k = 1; $k <= $h; $k++) {
            $idx   = $n - $m + (($k - 1) % $m);
            $out[] = $y[$idx] + $shift;
        }
        return $out;
    }

    /** Additive Holt-Winters with a yearly (52-week) season. Needs 2 full years. */
    public static function holtWinters(array $y, int $h): array
    {
        $n = count($y);
        $m = self::SEASON;
        $best = null;

        $first  = array_slice($y, 0, $m);
        $second = array_slice($y, $m, $m);
        $l0 = Stats::mean($first);
        $t0 = (Stats::mean($second) - $l0) / $m;
        $s0 = array_map(fn($v) => $v - $l0, $first);

        foreach ([0.1, 0.3] as $a) {
            foreach ([0.01, 0.05] as $b) {
                foreach ([0.1, 0.3] as $g) {
                    $l = $l0; $t = $t0; $s = $s0; $sse = 0.0;
                    for ($i = $m; $i < $n; $i++) {
                        $si   = $s[$i % $m];
                        $pred = $l + $t + $si;
                        $sse += ($y[$i] - $pred) ** 2;
                        $lNew = $a * ($y[$i] - $si) + (1 - $a) * ($l + $t);
                        $t    = $b * ($lNew - $l) + (1 - $b) * $t;
                        $s[$i % $m] = $g * ($y[$i] - $lNew) + (1 - $g) * $si;
                        $l    = $lNew;
                    }
                    if ($best === null || $sse < $best[0] - 1e-9) $best = [$sse, $l, $t, $s];
                }
            }
        }
        [, $l, $t, $s] = $best;
        $out = [];
        for ($k = 1; $k <= $h; $k++) {
            $out[] = $l + $k * $t + $s[($n + $k - 1) % $m];
        }
        return $out;
    }

    /**
     * Average inter-demand interval > 1.32 → intermittent (Syntetos–Boylan).
     */
    public static function isIntermittent(array $y): bool
    {
        $nz = count(array_filter($y, fn($v) => $v > 0));
        return $nz === 0 || count($y) / $nz > 1.32;
    }

    /**
     * Pick the model with the lowest mean absolute error when forecasting the
     * last weeks from the weeks before them (two rolling origins when the series
     * is long enough). Returns [model, mae|null, candidates => [model => mae]].
     */
    public static function select(array $y): array
    {
        $n            = count($y);
        $intermittent = self::isIntermittent($y);
        // Intermittent demand: Croston family only (plain mean as the benchmark).
        $candidates   = $intermittent
            ? ['mean', 'sba', 'croston']
            : ['mean', 'ses', 'holt', 'seasonal_naive', 'holt_winters'];

        // Long series: 13-week holdouts spread over the last year, so a model that
        // knows the season gets credit for it. Short series: last 4 + previous 4 weeks.
        $holdout = 4;
        $origins = [];
        if (!$intermittent && $n >= self::MIN_TRAIN['seasonal_naive'] + 13) {
            $holdout = 13;
            foreach ([13, 26, 39, 52] as $back) {
                if ($n - $back >= self::MIN_TRAIN['seasonal_naive']) $origins[] = $n - $back;
            }
        } else {
            foreach ([$n - 4, $n - 8] as $o) {
                if ($o >= self::MIN_TRAIN['mean']) $origins[] = $o;
            }
        }
        if (!$origins) {
            return ['model' => $intermittent ? 'sba' : 'ses', 'mae' => null, 'candidates' => [], 'intermittent' => $intermittent];
        }

        $scores = [];
        foreach ($candidates as $model) {
            $errs = [];
            foreach ($origins as $o) {
                if ($o < self::MIN_TRAIN[$model]) continue 2;
                $train = array_slice($y, 0, $o);
                $test  = array_slice($y, $o, $holdout);
                $pred  = self::forecast($model, $train, count($test));
                foreach ($test as $i => $actual) $errs[] = abs($actual - $pred[$i]);
            }
            $scores[$model] = Stats::mean($errs);
        }

        // Lowest error; a more complex model must beat a simpler one by > 2 %.
        $chosen = null;
        foreach (self::ORDER as $model) {
            if (!isset($scores[$model])) continue;
            if ($chosen === null || $scores[$model] < $scores[$chosen] * 0.98) $chosen = $model;
        }

        return ['model' => $chosen, 'mae' => $scores[$chosen], 'candidates' => $scores, 'intermittent' => $intermittent];
    }
}

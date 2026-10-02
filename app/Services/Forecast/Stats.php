<?php

namespace App\Services\Forecast;

/**
 * Count distributions used for forecast ranges.
 *
 * Sales are counts, often small, so ranges come from Poisson / negative-binomial
 * quantiles instead of a normal approximation (which would give negative lows
 * and symmetric ranges for a product selling 1–2 units a month).
 */
final class Stats
{
    /** Above this mean the normal approximation is accurate and much cheaper. */
    private const NORMAL_FROM = 250.0;

    /**
     * Smallest k with P(X ≤ k) ≥ q for a count with this mean and variance
     * (Poisson when variance ≤ mean, negative binomial otherwise).
     */
    public static function quantile(float $mean, float $variance, float $q): int
    {
        return self::mixtureQuantile([[1.0, $mean, $variance]], $q);
    }

    /**
     * Quantile of a weighted mixture of counts: [[weight, mean, variance], …].
     * Used for the category estimate ("a product like yours sells like a random
     * similar product").
     */
    public static function mixtureQuantile(array $components, float $q): int
    {
        $components = array_values(array_filter($components, fn($c) => $c[0] > 0));
        if (!$components) return 0;

        $totalW = array_sum(array_column($components, 0));
        $maxK   = 0;
        foreach ($components as $c) {
            $sd   = sqrt(max($c[2], $c[1], 0.0));
            $maxK = max($maxK, (int) ceil($c[1] + 10 * $sd + 10));
        }

        // All-normal fast path
        if (count($components) === 1 && $components[0][1] >= self::NORMAL_FROM) {
            [, $m, $v] = $components[0];
            return max(0, (int) round($m + self::normalInv($q) * sqrt(max($v, $m))));
        }

        $iters = [];
        foreach ($components as $c) $iters[] = self::pmfIterator($c[1], $c[2]);

        $cdf = 0.0;
        for ($k = 0; $k <= $maxK; $k++) {
            foreach ($components as $i => $c) {
                $cdf += ($c[0] / $totalW) * $iters[$i]($k);
            }
            if ($cdf >= $q - 1e-12) return $k;
        }
        return $maxK;
    }

    /**
     * Returns fn(int $k): float giving P(X = k), called with k = 0, 1, 2, … in order.
     */
    private static function pmfIterator(float $mean, float $variance): \Closure
    {
        $mean = max(0.0, $mean);
        if ($mean <= 0.0) {
            return fn(int $k) => $k === 0 ? 1.0 : 0.0;
        }

        if ($mean >= self::NORMAL_FROM) {
            $sd = sqrt(max($variance, $mean));
            return function (int $k) use ($mean, $sd) {
                return self::normalCdf(($k + 0.5 - $mean) / $sd) - self::normalCdf(($k - 0.5 - $mean) / $sd);
            };
        }

        if ($variance <= $mean * 1.0001) {
            $p = null;
            return function (int $k) use ($mean, &$p) {
                $p = $k === 0 ? exp(-$mean) : $p * $mean / $k;
                return $p;
            };
        }

        // Negative binomial: r = mean² / (variance − mean), success prob p = r / (r + mean)
        $r    = $mean * $mean / ($variance - $mean);
        $prob = $r / ($r + $mean);
        $p    = null;
        return function (int $k) use ($r, $prob, &$p) {
            $p = $k === 0 ? exp($r * log($prob)) : $p * ($k - 1 + $r) / $k * (1 - $prob);
            return $p;
        };
    }

    /** [low, high] of the central interval (default 80 %: P10–P90). */
    public static function interval(float $mean, float $variance, float $coverage = 0.8): array
    {
        $tail = (1 - $coverage) / 2;
        return [self::quantile($mean, $variance, $tail), self::quantile($mean, $variance, 1 - $tail)];
    }

    public static function mixtureInterval(array $components, float $coverage = 0.8): array
    {
        $tail = (1 - $coverage) / 2;
        return [self::mixtureQuantile($components, $tail), self::mixtureQuantile($components, 1 - $tail)];
    }

    public static function mean(array $xs): float
    {
        return $xs ? array_sum($xs) / count($xs) : 0.0;
    }

    public static function variance(array $xs): float
    {
        $n = count($xs);
        if ($n < 2) return 0.0;
        $m = self::mean($xs);
        $s = 0.0;
        foreach ($xs as $x) $s += ($x - $m) ** 2;
        return $s / ($n - 1);
    }

    public static function median(array $xs): float
    {
        if (!$xs) return 0.0;
        sort($xs);
        $n = count($xs);
        return $n % 2 ? (float) $xs[intdiv($n, 2)] : ($xs[$n / 2 - 1] + $xs[$n / 2]) / 2;
    }

    public static function normalCdf(float $z): float
    {
        // Abramowitz–Stegun 7.1.26
        $t = 1 / (1 + 0.3275911 * abs($z) / M_SQRT2);
        $y = 1 - ((((1.061405429 * $t - 1.453152027) * $t + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t * exp(-$z * $z / 2);
        return $z >= 0 ? (1 + $y) / 2 : (1 - $y) / 2;
    }

    /** Inverse normal CDF (Acklam), enough precision for interval bounds. */
    public static function normalInv(float $p): float
    {
        $p = min(max($p, 1e-9), 1 - 1e-9);
        $a = [-39.69683028665376, 220.9460984245205, -275.9285104469687, 138.3577518672690, -30.66479806614716, 2.506628277459239];
        $b = [-54.47609879822406, 161.5858368580409, -155.6989798598866, 66.80131188771972, -13.28068155288572];
        $c = [-0.007784894002430293, -0.3223964580411365, -2.400758277161838, -2.549732539343734, 4.374664141464968, 2.938163982698783];
        $d = [0.007784695709041462, 0.3224671290700398, 2.445134137142996, 3.754408661907416];
        if ($p < 0.02425) {
            $q = sqrt(-2 * log($p));
            return ((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5]) / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
        }
        if ($p > 1 - 0.02425) {
            return -self::normalInv(1 - $p);
        }
        $q = $p - 0.5;
        $r = $q * $q;
        return ((((($a[0] * $r + $a[1]) * $r + $a[2]) * $r + $a[3]) * $r + $a[4]) * $r + $a[5]) * $q / ((((($b[0] * $r + $b[1]) * $r + $b[2]) * $r + $b[3]) * $r + $b[4]) * $r + 1);
    }
}

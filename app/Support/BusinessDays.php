<?php

namespace App\Support;

use App\Services\Delivery\DeliveryTimeSettings;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Business-day arithmetic in Africa/Tunis. Same rules as the storefront's
 * lib/deliveryEstimate.ts: a business day is a working weekday that is not a holiday;
 * addBusinessDays steps one calendar day at a time and counts only business days.
 */
class BusinessDays
{
    public static function isBusinessDay(CarbonInterface $day, array $settings): bool
    {
        return in_array($day->dayOfWeekIso, $settings['working_days'], true)
            && !in_array($day->toDateString(), $settings['holidays'], true);
    }

    /** Calendar date (Tunis, 00:00) reached after $k business days. $k = 0 returns the same date. */
    public static function add(CarbonInterface $from, int $k, ?array $settings = null): Carbon
    {
        $settings ??= DeliveryTimeSettings::all();
        $d = Carbon::instance($from)->timezone('Africa/Tunis')->startOfDay();
        // Guard against a settings set with no working day at all
        if (!$settings['working_days']) {
            return $d;
        }
        while ($k > 0) {
            $d->addDay();
            if (self::isBusinessDay($d, $settings)) {
                $k--;
            }
        }
        return $d;
    }
}

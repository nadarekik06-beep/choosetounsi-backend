<?php

namespace App\Services\Delivery;

use App\Models\PlatformSetting;
use Carbon\Carbon;

/**
 * Admin-editable delivery-time rules behind the storefront's delivery estimate
 * and the seller reminder timing (platform_settings key `delivery_time`).
 * Business days = `working_days` (ISO 1=Mon … 7=Sun) minus `holidays`.
 * The storefront mirrors the same rules in lib/deliveryEstimate.ts.
 */
class DeliveryTimeSettings
{
    public const KEY = 'delivery_time';

    /** Fixed-date Tunisian public holidays (month-day). Islamic holidays are added by the admin. */
    public const FIXED_HOLIDAYS = ['01-01', '03-20', '04-09', '05-01', '07-25', '08-13', '10-15', '12-17'];

    public static function defaults(?Carbon $from = null): array
    {
        return [
            'pickup_delay_min_business_days' => 1,
            'pickup_delay_max_business_days' => 2,
            'transit_business_days'          => 1,
            'working_days'                   => [1, 2, 3, 4, 5],
            'holidays'                       => self::fixedHolidaysFrom($from ?? Carbon::now('Africa/Tunis')),
            'order_cutoff_time'              => '23:59',
            'timezone'                       => 'Africa/Tunis',
        ];
    }

    /** Fixed holidays falling in the 12 months starting at $from's date. */
    public static function fixedHolidaysFrom(Carbon $from): array
    {
        $start = $from->copy()->startOfDay();
        $end   = $start->copy()->addYear();
        $out   = [];
        foreach ([$start->year, $start->year + 1] as $year) {
            foreach (self::FIXED_HOLIDAYS as $md) {
                $d = Carbon::parse("$year-$md", 'Africa/Tunis');
                if ($d->gte($start) && $d->lt($end)) {
                    $out[] = $d->toDateString();
                }
            }
        }
        sort($out);
        return $out;
    }

    public static function all(): array
    {
        $stored = PlatformSetting::getValue(self::KEY);
        $base   = self::defaults();
        if (!is_array($stored)) {
            return $base;
        }
        return array_merge($base, array_intersect_key($stored, $base));
    }

    public static function update(array $data, ?int $userId = null): array
    {
        $clean = [
            'pickup_delay_min_business_days' => (int) $data['pickup_delay_min_business_days'],
            'pickup_delay_max_business_days' => (int) $data['pickup_delay_max_business_days'],
            'transit_business_days'          => (int) $data['transit_business_days'],
            'working_days'                   => array_values(array_unique(array_map('intval', $data['working_days']))),
            'holidays'                       => array_values(array_unique($data['holidays'] ?? [])),
            'order_cutoff_time'              => $data['order_cutoff_time'],
            'timezone'                       => 'Africa/Tunis',
        ];
        sort($clean['working_days']);
        sort($clean['holidays']);
        PlatformSetting::setValue(self::KEY, $clean, $userId);
        return $clean;
    }
}

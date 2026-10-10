<?php

namespace App\Services\Orders\WhatsApp;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * When a seller reminder becomes due: N working hours after $from, in the
 * Tunis working day (config/seller_whatsapp.php).
 *
 *   during working hours   $from + N hours, or the next day at 08:30 if that
 *                          lands after the end of the day (20:00)
 *   at night (20:00–08:00) the next morning at 08:30
 */
class ReminderSchedule
{
    /** @return Carbon in the app timezone (UTC) */
    public static function dueAfter(CarbonInterface $from, int $hours): Carbon
    {
        $config = config('seller_whatsapp');
        $local  = Carbon::instance($from)->timezone($config['timezone']);

        $start = self::at($local, $config['working_hours']['start']);
        $end   = self::at($local, $config['working_hours']['end']);

        if ($local->lt($start)) {
            $due = self::at($local, $config['morning_time']);                    // before opening: this morning
        } elseif ($local->gte($end)) {
            $due = self::at($local->copy()->addDay(), $config['morning_time']);  // evening: tomorrow morning
        } else {
            $due = $local->copy()->addHours($hours);
            if ($due->gt($end)) {
                $due = self::at($local->copy()->addDay(), $config['morning_time']);
            }
        }

        return $due->timezone(config('app.timezone'));
    }

    /** "HH:MM" on $day's date, in $day's timezone. */
    private static function at(Carbon $day, string $time): Carbon
    {
        [$h, $m] = array_map('intval', explode(':', $time) + [1 => 0]);
        return $day->copy()->setTime($h, $m);
    }
}

<?php

namespace App\Services\Ads;

use Carbon\Carbon;

/**
 * Ad time. The app runs in UTC, but everything day-shaped in the ad engine —
 * daily budgets, click-charge roll-ups, credit expiry, daily stats — follows
 * the Africa/Tunis calendar (config('ads.timezone')).
 */
class AdClock
{
    public static function timezone(): string
    {
        return (string) config('ads.timezone', 'Africa/Tunis');
    }

    /**
     * Now, in ad time — for calendar maths only. Never store a Carbon from here
     * directly: Eloquent writes the wall-clock time without converting, so use
     * toStorage() first.
     */
    public static function now(): Carbon
    {
        return Carbon::now(self::timezone());
    }

    /** Today's date (Y-m-d) in ad time. */
    public static function today(): string
    {
        return self::now()->toDateString();
    }

    /** Last second of the current ad-time month, converted for storage. */
    public static function endOfMonth(): Carbon
    {
        return self::toStorage(self::now()->endOfMonth());
    }

    /** Convert an ad-time instant to the app timezone before it is saved. */
    public static function toStorage(Carbon $instant): Carbon
    {
        return $instant->copy()->setTimezone(config('app.timezone'));
    }

    /** A stored instant's date (Y-m-d) in ad time. */
    public static function dateOf(Carbon $instant): string
    {
        return $instant->copy()->setTimezone(self::timezone())->toDateString();
    }
}

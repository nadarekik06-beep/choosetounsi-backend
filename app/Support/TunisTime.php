<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Dates typed in a form are Tunis wall-clock time (AUDIT_DISCOUNTS L-3).
 * Stored and compared in UTC (config/app.php timezone). Tunis is UTC+1.
 *
 *   "2026-11-06T10:00"            → 09:00 UTC (no offset: read as Africa/Tunis)
 *   "2026-11-06T10:00:00+01:00"   → 09:00 UTC
 *   "2026-11-06T09:00:00.000Z"    → 09:00 UTC
 */
class TunisTime
{
    public const ZONE = 'Africa/Tunis';

    public static function toUtc($value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->utc();
        }
        return Carbon::parse($value, self::ZONE)->utc();
    }

    /** "Y-m-d H:i:s" in UTC, for a datetime column. */
    public static function toUtcString($value): ?string
    {
        return self::toUtc($value)?->toDateTimeString();
    }
}

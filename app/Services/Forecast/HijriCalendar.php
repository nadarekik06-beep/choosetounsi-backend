<?php

namespace App\Services\Forecast;

use Carbon\CarbonImmutable;

/**
 * Tabular (arithmetic) Islamic calendar ↔ Gregorian, no extension needed.
 *
 * The tabular calendar can differ from the moon sighting used in Tunisia by
 * 1–2 days, so dates computed here are stored with source "hijri" and the admin
 * is asked to check them (Mufti announcement) and edit if needed.
 */
final class HijriCalendar
{
    private const EPOCH_JD = 1948439.5; // 1 Muharram 1 AH (Julian day, civil epoch)

    /** Known yearly events: key => [hijri month, day, length in days, FR, EN, AR]. */
    public const EVENTS = [
        'ramadan'       => [9, 1, 30, 'Ramadan', 'Ramadan', 'رمضان'],
        'aid_fitr'      => [10, 1, 3, 'Aïd el-Fitr', 'Eid al-Fitr', 'عيد الفطر'],
        'aid_adha'      => [12, 10, 3, 'Aïd el-Idha', 'Eid al-Adha', 'عيد الأضحى'],
        'ras_el_am'     => [1, 1, 1, 'Ras el Am el Hijri', 'Islamic New Year', 'رأس السنة الهجرية'],
        'mouled'        => [3, 12, 1, 'Mouled', 'Mawlid', 'المولد النبوي'],
    ];

    public static function toGregorian(int $year, int $month, int $day): CarbonImmutable
    {
        $jd = $day
            + (int) ceil(29.5 * ($month - 1))
            + ($year - 1) * 354
            + intdiv(3 + 11 * $year, 30)
            + self::EPOCH_JD - 1;
        return self::fromJulianDay($jd);
    }

    /** [year, month, day] of the Hijri date containing this Gregorian date. */
    public static function fromGregorian(\DateTimeInterface $date): array
    {
        $jd    = self::toJulianDay((int) $date->format('Y'), (int) $date->format('n'), (int) $date->format('j'));
        $jd    = floor($jd) + 0.5;
        $year  = (int) floor((30 * ($jd - self::EPOCH_JD) + 10646) / 10631);
        $month = (int) min(12, ceil(($jd - (29 + self::toJdHijri($year, 1, 1))) / 29.5) + 1);
        $day   = (int) ($jd - self::toJdHijri($year, $month, 1) + 1);
        return [$year, $month, $day];
    }

    /**
     * Gregorian occurrences of the known events that start in this Gregorian year.
     * @return array<int, array{key:string, starts_on:string, ends_on:string, name_fr:string, name_en:string, name_ar:string}>
     */
    public static function eventsForGregorianYear(int $gYear): array
    {
        [$hStart] = self::fromGregorian(new \DateTimeImmutable("$gYear-01-01"));
        $out = [];
        foreach ([$hStart, $hStart + 1] as $hYear) {
            foreach (self::EVENTS as $key => [$m, $d, $len, $fr, $en, $ar]) {
                $start = self::toGregorian($hYear, $m, $d);
                if ((int) $start->format('Y') !== $gYear) continue;
                $out[] = [
                    'key'       => $key,
                    'starts_on' => $start->toDateString(),
                    'ends_on'   => $start->addDays($len - 1)->toDateString(),
                    'name_fr'   => $fr,
                    'name_en'   => $en,
                    'name_ar'   => $ar,
                ];
            }
        }
        usort($out, fn($a, $b) => strcmp($a['starts_on'], $b['starts_on']));
        return $out;
    }

    private static function toJdHijri(int $year, int $month, int $day): float
    {
        return $day + ceil(29.5 * ($month - 1)) + ($year - 1) * 354 + floor((3 + 11 * $year) / 30) + self::EPOCH_JD - 1;
    }

    private static function toJulianDay(int $y, int $m, int $d): float
    {
        if ($m <= 2) { $y--; $m += 12; }
        $a = intdiv($y, 100);
        $b = 2 - $a + intdiv($a, 4);
        return floor(365.25 * ($y + 4716)) + floor(30.6001 * ($m + 1)) + $d + $b - 1524.5;
    }

    private static function fromJulianDay(float $jd): CarbonImmutable
    {
        $z = (int) floor($jd + 0.5);
        $a = $z;
        if ($z >= 2299161) {
            $alpha = (int) floor(($z - 1867216.25) / 36524.25);
            $a = $z + 1 + $alpha - intdiv($alpha, 4);
        }
        $b = $a + 1524;
        $c = (int) floor(($b - 122.1) / 365.25);
        $d = (int) floor(365.25 * $c);
        $e = (int) floor(($b - $d) / 30.6001);
        $day   = $b - $d - (int) floor(30.6001 * $e);
        $month = $e < 14 ? $e - 1 : $e - 13;
        $year  = $month > 2 ? $c - 4716 : $c - 4715;
        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, 'UTC');
    }
}

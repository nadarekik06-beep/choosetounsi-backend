<?php

namespace App\Services\Forecast;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Fixed-date Tunisian shopping moments (back to school, summer, the two sales
 * periods, wedding season, Black Friday, New Year), for the admin calendar next
 * to the Islamic holidays. Dates are typical windows — the admin adjusts them
 * (official sales dates are announced every year). Each one applies to the
 * categories listed by slug; none = every category.
 */
class TunisianSeasons
{
    /** key => [start "m-d" | callable(int $year): CarbonImmutable, length in days, fr, en, ar, category slugs] */
    private static function definitions(): array
    {
        return [
            'winter_sales'   => ['02-01', 42, 'Soldes d\'hiver', 'Winter sales', 'تخفيضات الشتاء', ['fashion-clothing', 'home-living', 'sports-outdoors']],
            'wedding_season' => ['06-15', 92, 'Saison des mariages', 'Wedding season', 'موسم الأعراس', ['fashion-clothing', 'beauty-personal-care', 'home-living', 'arts-crafts']],
            'summer'         => ['07-01', 62, 'Été', 'Summer', 'الصيف', ['fashion-clothing', 'sports-outdoors', 'beauty-personal-care', 'health-wellness']],
            'summer_sales'   => ['08-07', 42, 'Soldes d\'été', 'Summer sales', 'تخفيضات الصيف', ['fashion-clothing', 'home-living', 'sports-outdoors']],
            'back_to_school' => ['09-01', 21, 'Rentrée scolaire', 'Back to school', 'العودة المدرسية', ['books-stationery', 'kids-baby', 'fashion-clothing', 'electronics-tech']],
            'black_friday'   => [fn (int $y) => CarbonImmutable::create($y, 11, 30)->modify('last friday of november'), 4, 'Black Friday', 'Black Friday', 'الجمعة البيضاء', []],
            'new_year'       => ['12-20', 12, 'Fêtes de fin d\'année', 'New Year', 'رأس السنة', []],
        ];
    }

    /** @return array<int, array{key, starts_on, ends_on, name_fr, name_en, name_ar, category_ids}> */
    public static function eventsForYear(int $year): array
    {
        $ids = DB::table('categories')->pluck('id', 'slug');
        $out = [];
        foreach (self::definitions() as $key => [$start, $len, $fr, $en, $ar, $slugs]) {
            $s = is_callable($start) ? $start($year) : CarbonImmutable::parse("$year-$start");
            $cats = array_values(array_filter(array_map(fn ($slug) => $ids[$slug] ?? null, $slugs)));
            $out[] = [
                'key' => $key, 'starts_on' => $s->toDateString(), 'ends_on' => $s->addDays($len - 1)->toDateString(),
                'name_fr' => $fr, 'name_en' => $en, 'name_ar' => $ar,
                'category_ids' => $cats ? array_map('intval', $cats) : null,
            ];
        }
        return $out;
    }
}

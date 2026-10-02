<?php

namespace App\Services\Forecast;

use App\Models\CalendarEvent;
use App\Models\CalendarEventEffect;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Calendar events for a category, with the effect measured the last time the
 * same event (same key) happened — when one exists.
 */
class Calendar
{
    private ?array $events = null;
    private array $effects = [];

    /**
     * Events overlapping [today − 91 days, today + 224 days] (chart markers +
     * forecast window) that apply to this category.
     */
    public function forCategory(int $categoryId, CarbonImmutable $today): array
    {
        $out = [];
        foreach ($this->events($today) as $ev) {
            if (!$ev->appliesTo($categoryId)) continue;
            $out[] = [
                'id'        => $ev->id,
                'key'       => $ev->key,
                'names'     => $ev->names(),
                'starts_on' => $ev->starts_on->toDateString(),
                'ends_on'   => $ev->ends_on->toDateString(),
                'effect'    => $this->lastEffect($ev->key, $categoryId, $ev->starts_on->toDateString()),
            ];
        }
        return $out;
    }

    /** Most recent measured effect of an earlier occurrence of this event in this category. */
    public function lastEffect(string $key, int $categoryId, string $before): ?array
    {
        $memo = "$key|$categoryId|$before";
        if (array_key_exists($memo, $this->effects)) return $this->effects[$memo];

        $e = CalendarEventEffect::query()
            ->join('calendar_events as ce', 'ce.id', '=', 'calendar_event_effects.calendar_event_id')
            ->where('ce.key', $key)
            ->where('calendar_event_effects.category_id', $categoryId)
            ->where('ce.ends_on', '<', $before)
            ->orderByDesc('ce.starts_on')
            ->select('calendar_event_effects.*', 'ce.starts_on as occurred_on')
            ->first();

        return $this->effects[$memo] = $e ? [
            'change_pct'      => $e->change_pct,
            'event_orders'    => $e->event_orders,
            'baseline_orders' => $e->baseline_orders,
            'year'            => (int) substr((string) $e->occurred_on, 0, 4),
        ] : null;
    }

    private function events(CarbonImmutable $today)
    {
        return $this->events ??= CalendarEvent::query()
            ->where('is_active', true)
            ->where('ends_on', '>=', $today->subDays(91)->toDateString())
            ->where('starts_on', '<=', $today->addDays(224)->toDateString())
            ->orderBy('starts_on')
            ->get()->all();
    }

    /**
     * Add computed Islamic-holiday dates for a Gregorian year (source = hijri,
     * to be checked by the admin). Existing rows for the same key and year are
     * left untouched. Returns the number of events created.
     */
    public static function seedHijriYear(int $year): int
    {
        $created = 0;
        foreach (HijriCalendar::eventsForGregorianYear($year) as $e) {
            $exists = DB::table('calendar_events')->where('key', $e['key'])->whereYear('starts_on', $year)->exists();
            if ($exists) continue;
            CalendarEvent::create($e + ['source' => 'hijri', 'is_active' => true, 'category_ids' => null]);
            $created++;
        }
        return $created;
    }
}

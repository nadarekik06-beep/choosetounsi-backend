<?php

namespace App\Services\Forecast;

use App\Models\CalendarEvent;
use App\Models\CalendarEventEffect;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * After an event, measure per category how daily sales during the event
 * compared with the surrounding weeks (N weeks before + N weeks after, other
 * events' days left out). Stored with the order counts on both sides; the
 * forecast uses an effect only when both reach forecast.event_effect_min_orders.
 */
class EventEffectMeasurer
{
    /** Events whose after-window is complete and that are unmeasured or recent. */
    public function measurable(CarbonImmutable $today)
    {
        $weeks = (int) config('forecast.event_baseline_weeks');
        return CalendarEvent::query()
            ->where('ends_on', '<', $today->subDays(7 * $weeks)->toDateString())
            ->where('ends_on', '>=', $today->subDays(400)->toDateString())
            ->where(fn($q) => $q->whereDoesntHave('effects')
                ->orWhere('ends_on', '>=', $today->subDays(7 * $weeks + 30)->toDateString()))
            ->get();
    }

    /** @return int number of categories measured */
    public function measure(CalendarEvent $event): int
    {
        $tz    = config('forecast.timezone');
        $weeks = (int) config('forecast.event_baseline_weeks');
        $start = CarbonImmutable::parse($event->starts_on->toDateString(), $tz);
        $end   = CarbonImmutable::parse($event->ends_on->toDateString(), $tz);
        $from  = $start->subDays(7 * $weeks);
        $to    = $end->addDays(7 * $weeks);

        // Days of the baseline window that belong to another event are skipped.
        $otherDays = [];
        $others = CalendarEvent::query()->where('id', '!=', $event->id)->where('is_active', true)
            ->where('ends_on', '>=', $from->toDateString())->where('starts_on', '<=', $to->toDateString())->get();
        foreach ($others as $o) {
            for ($d = CarbonImmutable::parse($o->starts_on->toDateString()); $d <= $o->ends_on; $d = $d->addDay()) {
                $otherDays[$d->toDateString()] = true;
            }
        }

        $rows = DB::table('order_items as oi')
            ->join('seller_orders as so', 'so.id', '=', 'oi.seller_order_id')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->whereIn('so.status', config('forecast.sale_statuses'))
            ->where('so.created_at', '>=', $from->utc())
            ->where('so.created_at', '<', $to->addDay()->utc())
            ->whereNotNull('p.category_id')
            ->select('p.category_id', 'oi.quantity', 'so.id as so_id', 'so.created_at')
            ->get();

        $agg = [];   // category => [eventUnits, eventOrders[], baseUnits, baseOrders[]]
        foreach ($rows as $r) {
            $day = CarbonImmutable::parse($r->created_at, 'UTC')->tz($tz)->toDateString();
            $inEvent = $day >= $start->toDateString() && $day <= $end->toDateString();
            if (!$inEvent && isset($otherDays[$day])) continue;
            $a = &$agg[$r->category_id];
            $a ??= [0, [], 0, []];
            if ($inEvent) { $a[0] += (int) $r->quantity; $a[1][$r->so_id] = true; }
            else          { $a[2] += (int) $r->quantity; $a[3][$r->so_id] = true; }
            unset($a);
        }

        $eventDays = (int) $start->diffInDays($end) + 1;
        $baseDays  = 0;
        for ($d = $from; $d <= $to; $d = $d->addDay()) {
            $s = $d->toDateString();
            if (($s < $start->toDateString() || $s > $end->toDateString()) && !isset($otherDays[$s])) $baseDays++;
        }

        foreach ($agg as $categoryId => [$eu, $eo, $bu, $bo]) {
            $er = $eu / max(1, $eventDays);
            $br = $baseDays > 0 ? $bu / $baseDays : 0.0;
            CalendarEventEffect::updateOrCreate(
                ['calendar_event_id' => $event->id, 'category_id' => $categoryId],
                [
                    'event_daily_units'    => round($er, 3),
                    'baseline_daily_units' => round($br, 3),
                    'change_pct'           => $br > 0 ? round(($er / $br - 1) * 100, 2) : null,
                    'event_orders'         => count($eo),
                    'baseline_orders'      => count($bo),
                    'event_days'           => $eventDays,
                    'baseline_days'        => $baseDays,
                ]
            );
        }
        return count($agg);
    }
}

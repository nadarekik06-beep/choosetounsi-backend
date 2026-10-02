<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Services\Forecast\Calendar;
use App\Services\Forecast\EventEffectMeasurer;
use Illuminate\Http\Request;

/**
 * Admin → Calendrier tunisien. Events are data (dates, names, categories):
 * there is deliberately no "uplift %" field — effects are measured from sales.
 */
class CalendarEventController extends Controller
{
    public function index(Request $request)
    {
        $year = (int) $request->query('year', date('Y'));
        $events = CalendarEvent::query()
            ->whereYear('starts_on', $year)
            ->with(['effects' => fn($q) => $q->orderByDesc('event_orders')])
            ->orderBy('starts_on')->get()
            ->map(fn(CalendarEvent $e) => $e->toArray() + [
                'effects_summary' => $e->effects->map(fn($f) => [
                    'category_id' => $f->category_id, 'change_pct' => $f->change_pct,
                    'event_orders' => $f->event_orders, 'baseline_orders' => $f->baseline_orders,
                    'reliable' => $f->isReliable(),
                ])->values(),
            ]);
        return response()->json(['success' => true, 'data' => $events, 'min_orders' => (int) config('forecast.event_effect_min_orders')]);
    }

    public function store(Request $request)
    {
        $event = CalendarEvent::create($this->validated($request) + ['source' => 'admin']);
        return response()->json(['success' => true, 'data' => $event], 201);
    }

    public function update(Request $request, int $id)
    {
        $event = CalendarEvent::findOrFail($id);
        // Once an admin has checked computed dates, they're the admin's.
        $event->update($this->validated($request, $event) + ['source' => 'admin']);
        return response()->json(['success' => true, 'data' => $event->fresh()]);
    }

    public function destroy(int $id)
    {
        CalendarEvent::findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }

    /** POST /admin/calendar-events/generate-hijri {year} */
    public function generateHijri(Request $request)
    {
        $data = $request->validate(['year' => 'required|integer|min:2020|max:2100']);
        return response()->json(['success' => true, 'created' => Calendar::seedHijriYear((int) $data['year'])]);
    }

    /** POST /admin/calendar-events/{id}/measure — re-measure a finished event now */
    public function measure(int $id, EventEffectMeasurer $measurer)
    {
        $event = CalendarEvent::findOrFail($id);
        if ($event->ends_on->isFuture()) {
            return response()->json(['success' => false, 'message' => 'Event not finished yet'], 422);
        }
        return response()->json(['success' => true, 'categories' => $measurer->measure($event)]);
    }

    private function validated(Request $request, ?CalendarEvent $event = null): array
    {
        $data = $request->validate([
            'key'            => 'required|string|max:40|regex:/^[a-z0-9_]+$/',
            'name_fr'        => 'required|string|max:120',
            'name_en'        => 'required|string|max:120',
            'name_ar'        => 'required|string|max:120',
            'starts_on'      => 'required|date',
            'ends_on'        => 'required|date|after_or_equal:starts_on',
            'category_ids'   => 'nullable|array',
            'category_ids.*' => 'integer|exists:categories,id',
            'is_active'      => 'sometimes|boolean',
        ]);
        $data['category_ids'] = !empty($data['category_ids']) ? array_values(array_map('intval', $data['category_ids'])) : null;

        $dup = CalendarEvent::where('key', $data['key'])->whereDate('starts_on', $data['starts_on'])
            ->when($event, fn($q) => $q->where('id', '!=', $event->id))->exists();
        abort_if($dup, 422, 'An event with this key already starts on that date.');
        return $data;
    }
}
